<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Portfolio;

use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Repository\PositionRepository;
use MyPortfolio\Repository\SnapshotRepository;
use MyPortfolio\Repository\TransactionRepository;
use MyPortfolio\Support\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * Zápis transakcí. Každá operace běží v DB transakci se zamčenou pozicí
 * (FOR UPDATE), po zápisu ověří, že držené množství nikdy neklesne pod nulu,
 * a přepočítá FIFO loty dotčeného instrumentu.
 */
final class TransactionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PositionRepository $positions,
        private readonly TransactionRepository $transactions,
        private readonly TransactionValidator $validator,
        private readonly HoldingCalculator $holdings,
        private readonly LotRebuilder $lots,
        private readonly SnapshotRepository $snapshots,
    ) {}

    /** @param array<string,mixed> $input */
    public function create(int $userId, int $positionId, array $input): int
    {
        return $this->inTransaction(function () use ($userId, $positionId, $input): int {
            $position = $this->lockedPosition($userId, $positionId);
            $data = $this->validate($input, $position);
            $id = $this->transactions->insert($userId, $positionId, $data);
            $this->assertHolding($userId, $position);
            $this->rebuildLots($userId, [$position]);
            $this->snapshots->markDirty($userId, $positionId, substr((string) $data['executed_at'], 0, 10));
            return $id;
        });
    }

    /**
     * Dávkový import do jedné pozice. Řádek = vstup jako u create() + `external_id`; řádky,
     * jejichž `external_id` uživatel pro `$source` už má, se přeskočí (opakovaný import = 0).
     *
     * @param list<array<string,mixed>> $rows
     * @return int počet vložených transakcí
     */
    public function import(int $userId, int $positionId, array $rows, string $source, int $batchId): int
    {
        return $this->inTransaction(function () use ($userId, $positionId, $rows, $source, $batchId): int {
            $position = $this->lockedPosition($userId, $positionId);
            $existing = array_flip($this->transactions->existingExternalIds(
                $userId, $source, array_map(static fn (array $r) => (string) $r['external_id'], $rows),
            ));
            $from = null;
            $count = 0;
            foreach ($rows as $row) {
                $externalId = (string) $row['external_id'];
                if (isset($existing[$externalId])) {
                    continue;
                }
                $data = $this->validate($row, $position);
                $this->transactions->insert($userId, $positionId, $data,
                    ['source' => $source, 'external_id' => $externalId, 'import_batch_id' => $batchId]);
                $from = min($from ?? $data['executed_at'], $data['executed_at']);
                $count++;
            }
            if ($count > 0) {
                $this->assertHolding($userId, $position);
                $this->rebuildLots($userId, [$position]);
                $this->snapshots->markDirty($userId, $positionId, substr((string) $from, 0, 10));
            }
            return $count;
        });
    }

    /** @param array<string,mixed> $input */
    public function update(int $userId, int $id, array $input): void
    {
        $this->inTransaction(function () use ($userId, $id, $input): void {
            $existing = $this->transactions->find($userId, $id) ?? throw DomainError::notFound('Transakce nenalezena.');
            if ($existing['transfer_group_id'] !== null) {
                throw new DomainError('transfer_readonly', 'Převod nelze upravit — smaž ho a založ znovu.', 409);
            }
            $position = $this->lockedPosition($userId, (int) $existing['position_id']);
            $data = $this->validate($input, $position);
            $this->transactions->update($userId, $id, $data);
            $this->assertHolding($userId, $position);
            $this->rebuildLots($userId, [$position]);
            $this->snapshots->markDirty($userId, (int) $position['id'],
                substr(min((string) $existing['executed_at'], (string) $data['executed_at']), 0, 10));
        });
    }

    /** Smaže transakci; u převodu obě nohy. */
    public function delete(int $userId, int $id): void
    {
        $this->inTransaction(function () use ($userId, $id): void {
            $existing = $this->transactions->find($userId, $id) ?? throw DomainError::notFound('Transakce nenalezena.');
            $ids = [$id];
            $positionIds = [(int) $existing['position_id']];
            if ($existing['transfer_group_id'] !== null) {
                $ids = $this->transactions->idsInTransferGroup($userId, (string) $existing['transfer_group_id']);
                foreach ($ids as $legId) {
                    // Každá noha musí být vidět (sdílení vybraných portfolií) — jinak nic nemazat
                    $leg = $this->transactions->find($userId, $legId) ?? throw DomainError::notFound('Transakce nenalezena.');
                    $positionIds[] = (int) $leg['position_id'];
                }
            }
            $positions = [];
            foreach ($this->sortedUnique($positionIds) as $positionId) {
                $positions[] = $this->lockedPosition($userId, $positionId);
            }
            $this->transactions->delete($userId, $ids);
            foreach ($positions as $position) {
                $this->snapshots->markDirty($userId, (int) $position['id'], substr((string) $existing['executed_at'], 0, 10));
            }
            foreach ($positions as $position) {
                $this->assertHolding($userId, $position);
            }
            $this->rebuildLots($userId, $positions);
        });
    }

    /**
     * Převod množství mezi dvěma tržními pozicemi téhož instrumentu (pár transfer_out + transfer_in).
     *
     * @param array<string,mixed> $input from_position_id, to_position_id, quantity, executed_at, fee?, fee_currency?, note?
     * @param array{source?:string,import_batch_id?:?int,external_id_out?:?string,external_id_in?:?string} $meta import:
     *        zdroj a dávka pro obě nohy, external_id jen pro nohu z importovaného účtu
     * @return array{transfer_group_id:string,ids:list<int>}
     */
    public function transfer(int $userId, array $input, array $meta = []): array
    {
        return $this->inTransaction(function () use ($userId, $input, $meta): array {
            $fromId = (int) ($input['from_position_id'] ?? 0);
            $toId = (int) ($input['to_position_id'] ?? 0);
            if ($fromId === $toId) {
                throw DomainError::validation(['to_position_id' => ['Zdrojová a cílová pozice musí být různé.']]);
            }
            $locked = [];
            foreach ($this->sortedUnique([$fromId, $toId]) as $positionId) {
                $locked[$positionId] = $this->lockedPosition($userId, $positionId);
            }
            $from = $locked[$fromId];
            $to = $locked[$toId];
            if ($from['valuation_mode'] !== 'market' || $to['valuation_mode'] !== 'market'
                || $from['instrument_id'] !== $to['instrument_id']
            ) {
                throw DomainError::validation([
                    'to_position_id' => ['Převádět lze jen mezi tržními pozicemi stejného instrumentu.'],
                ]);
            }

            $common = [
                'quantity'    => $input['quantity'] ?? null,
                'executed_at' => $input['executed_at'] ?? null,
                'note'        => $input['note'] ?? null,
            ];
            $out = $this->validate([
                ...$common,
                'type'         => 'transfer_out',
                'fee'          => $input['fee'] ?? null,
                'fee_currency' => $input['fee_currency'] ?? null,
            ], $from, true);
            $in = $this->validate([...$common, 'type' => 'transfer_in'], $to, true);

            $group = Uuid::v4()->toRfc4122();
            $common = ['transfer_group_id' => $group, 'source' => $meta['source'] ?? 'manual', 'import_batch_id' => $meta['import_batch_id'] ?? null];
            $ids = [
                $this->transactions->insert($userId, $fromId, $out, $common + ['external_id' => $meta['external_id_out'] ?? null]),
                $this->transactions->insert($userId, $toId, $in, $common + ['external_id' => $meta['external_id_in'] ?? null]),
            ];
            $this->assertHolding($userId, $from);
            $this->rebuildLots($userId, [$from]);
            foreach ([$fromId, $toId] as $positionId) {
                $this->snapshots->markDirty($userId, $positionId, substr((string) $out['executed_at'], 0, 10));
            }

            return ['transfer_group_id' => $group, 'ids' => $ids];
        });
    }

    /**
     * Vrátí dávku importu: smaže její transakce (u převodů obě nohy) a ověří, že ostatní transakce
     * dotčených pozic dál sedí (jinak DomainError a nic se nezmění).
     *
     * @return int počet smazaných transakcí
     */
    public function revertBatch(int $userId, int $batchId): int
    {
        return $this->inTransaction(function () use ($userId, $batchId): int {
            $rows = $this->transactions->forBatch($userId, $batchId);
            $ids = [];
            $positionDates = [];
            foreach ($rows as $row) {
                $legs = $row['transfer_group_id'] !== null
                    ? $this->transactions->idsInTransferGroup($userId, (string) $row['transfer_group_id'])
                    : [(int) $row['id']];
                foreach ($legs as $legId) {
                    $leg = $legId === (int) $row['id'] ? $row : $this->transactions->find($userId, $legId);
                    if ($leg === null) {
                        // druhá noha převodu mimo sdílená portfolia — partner dávku vrátit nesmí
                        throw DomainError::notFound('Dávka nenalezena.');
                    }
                    $ids[$legId] = true;
                    $pid = (int) $leg['position_id'];
                    $date = substr((string) $leg['executed_at'], 0, 10);
                    $positionDates[$pid] = min($positionDates[$pid] ?? $date, $date);
                }
            }
            if ($ids === []) {
                return 0;
            }
            $positions = [];
            foreach ($this->sortedUnique(array_keys($positionDates)) as $pid) {
                $positions[] = $this->lockedPosition($userId, $pid);
            }
            $this->transactions->delete($userId, array_keys($ids));
            foreach ($positions as $position) {
                $this->assertHolding($userId, $position);
                $this->snapshots->markDirty($userId, (int) $position['id'], $positionDates[(int) $position['id']]);
            }
            $this->rebuildLots($userId, $positions);
            return count($ids);
        });
    }

    /** Aktuální držené množství (market) / čisté vklady (manual) jako string. */
    public function holding(int $userId, array $position): string
    {
        $result = $this->holdings->evaluate(
            $this->transactions->forPosition($userId, (int) $position['id']),
            (string) $position['valuation_mode'],
            $position['instrument_symbol'] ?? null,
        );
        return Decimal::out($result->holding->toString()) ?? '0';
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $position
     * @return array<string,?string>
     */
    private function validate(array $input, array $position, bool $allowTransfer = false): array
    {
        $result = $this->validator->normalize($input, $position, $allowTransfer);
        if ($result['errors'] !== []) {
            throw DomainError::validation($result['errors']);
        }
        return $result['data'];
    }

    /** @return array<string,mixed> */
    private function lockedPosition(int $userId, int $positionId): array
    {
        if (!$this->positions->lock($userId, $positionId)) {
            throw DomainError::notFound('Pozice nenalezena.');
        }
        return $this->positions->find($userId, $positionId) ?? throw DomainError::notFound('Pozice nenalezena.');
    }

    /** @param array<string,mixed> $position */
    private function assertHolding(int $userId, array $position): void
    {
        $result = $this->holdings->evaluate(
            $this->transactions->forPosition($userId, (int) $position['id']),
            (string) $position['valuation_mode'],
            $position['instrument_symbol'] ?? null,
        );
        if (!$result->isValid()) {
            $at = (string) ($result->firstNegative['executed_at'] ?? '');
            throw new DomainError(
                'insufficient_quantity',
                $position['valuation_mode'] === 'market'
                    ? 'V pozici by k ' . $at . ' nebylo dost kusů.'
                    : 'Výběry by k ' . $at . ' převýšily vklady.',
                422,
                ['position_id' => (int) $position['id'], 'executed_at' => $at],
            );
        }
    }

    /** @param list<array<string,mixed>> $positions */
    private function rebuildLots(int $userId, array $positions): void
    {
        $instrumentIds = [];
        foreach ($positions as $position) {
            if ($position['valuation_mode'] === 'market' && $position['instrument_id'] !== null) {
                $instrumentIds[(int) $position['instrument_id']] = true;
            }
        }
        foreach (array_keys($instrumentIds) as $instrumentId) {
            $this->lots->rebuild($userId, $instrumentId);
        }
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function sortedUnique(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);
        return $ids;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function inTransaction(callable $fn): mixed
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            return $fn(); // volající (import, dry-run) řídí transakci sám
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

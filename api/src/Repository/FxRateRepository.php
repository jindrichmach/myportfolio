<?php

declare(strict_types=1);

namespace MyPortfolio\Repository;

use MyPortfolio\Infrastructure\Database\Connection;
use PDO;

/**
 * Kurzy ČNB (Kč za `amount` jednotek měny) podle data platnosti. Globální data.
 */
final class FxRateRepository
{
    public function __construct(private readonly Connection $db) {}

    public function upsert(string $date, string $currency, int $amount, string $rate): void
    {
        $this->db->pdo()->prepare(
            'INSERT INTO fx_rates (date, currency, amount, rate) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount), rate = VALUES(rate), fetched_at = CURRENT_TIMESTAMP'
        )->execute([$date, $currency, $amount, $rate]);
    }

    public function hasDate(string $date): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM fx_rates WHERE date = ? LIMIT 1');
        $stmt->execute([$date]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Poslední platný kurz k datu (nejpozději `$date`, nejdříve `$notBefore`).
     *
     * @return array{date:string,amount:int,rate:string}|null
     */
    public function rateOnOrBefore(string $currency, string $date, string $notBefore): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT date, amount, rate FROM fx_rates
              WHERE currency = ? AND date <= ? AND date >= ?
              ORDER BY date DESC LIMIT 1'
        );
        $stmt->execute([$currency, $date, $notBefore]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : ['date' => $row['date'], 'amount' => (int) $row['amount'], 'rate' => (string) $row['rate']];
    }

    /** Zná ČNB tuto měnu (je pro ni aspoň jeden kurz)? Krypto (BNB…) ne. */
    public function knows(string $currency): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM fx_rates WHERE currency = ? LIMIT 1');
        $stmt->execute([strtoupper($currency)]);
        return $stmt->fetchColumn() !== false;
    }

    public function latestDate(): ?string
    {
        $date = $this->db->pdo()->query('SELECT MAX(date) FROM fx_rates')->fetchColumn();
        return $date !== false && $date !== null ? (string) $date : null;
    }

    /** @return list<array{currency:string,amount:int,rate:string}> kurzy k datu */
    public function ratesOn(string $date): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT currency, amount, rate FROM fx_rates WHERE date = ? ORDER BY currency');
        $stmt->execute([$date]);
        return array_map(
            static fn (array $r) => ['currency' => $r['currency'], 'amount' => (int) $r['amount'], 'rate' => (string) $r['rate']],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}

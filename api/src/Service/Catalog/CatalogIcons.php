<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Catalog;

use MyPortfolio\Repository\InstitutionRepository;
use MyPortfolio\Repository\InstrumentRepository;
use MyPortfolio\Service\Portfolio\DomainError;

/**
 * Ikony položek katalogu (`institutions` | `instruments`). Měnit je smí admin nebo autor položky.
 * Soubor, na který už nic neodkazuje, se po výměně / smazání uklidí.
 */
final class CatalogIcons
{
    public const KINDS = ['institutions', 'instruments'];

    public function __construct(
        private readonly IconStore $store,
        private readonly InstitutionRepository $institutions,
        private readonly InstrumentRepository $instruments,
    ) {}

    public static function url(?string $icon): ?string
    {
        return $icon !== null ? '/api/icons/' . $icon : null;
    }

    /**
     * Řádek katalogu pro API: `icon_url` + `can_edit_icon` místo `icon` a autora.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function present(array $row, int $userId, bool $isAdmin): array
    {
        $row['icon_url'] = self::url($row['icon'] ?? null);
        $row['can_edit_icon'] = $isAdmin || ($row['created_by_user_id'] ?? null) === $userId;
        unset($row['icon'], $row['created_by_user_id']);
        return $row;
    }

    /** @return string název nového souboru */
    public function set(string $kind, int $id, string $bytes, int $userId, bool $isAdmin): string
    {
        $row = $this->editable($kind, $id, $userId, $isAdmin);
        $file = $this->store->store($bytes);
        $this->repo($kind)->setIcon($id, $file);
        $this->release($row['icon']);
        return $file;
    }

    public function remove(string $kind, int $id, int $userId, bool $isAdmin): void
    {
        $row = $this->editable($kind, $id, $userId, $isAdmin);
        $this->repo($kind)->setIcon($id, null);
        $this->release($row['icon']);
    }

    /** Web instituce (pro stažení loga); null = položka nemá web. */
    public function institutionWebsite(int $id): ?string
    {
        $row = $this->institutions->find($id) ?? throw DomainError::notFound('Položka katalogu nenalezena.');
        return $row['website'] !== null && $row['website'] !== '' ? (string) $row['website'] : null;
    }

    public function hasNoIcon(string $kind, int $id): bool
    {
        $row = $this->repo($kind)->find($id);
        return $row !== null && $row['icon'] === null;
    }

    /** Nastaví ikonu jen položce, která žádnou nemá (import). @return bool nastaveno */
    public function setIfMissing(string $kind, int $id, string $bytes): bool
    {
        $row = $this->repo($kind)->find($id);
        if ($row === null || $row['icon'] !== null) {
            return false;
        }
        $this->repo($kind)->setIcon($id, $this->store->store($bytes));
        return true;
    }

    /** Smaže soubor, pokud ho neodkazuje žádná instituce ani instrument. */
    public function release(?string $file): void
    {
        if ($file !== null && $file !== '' && !$this->institutions->usesIcon($file) && !$this->instruments->usesIcon($file)) {
            $this->store->delete($file);
        }
    }

    /** @return array<string,mixed> */
    private function editable(string $kind, int $id, int $userId, bool $isAdmin): array
    {
        $row = $this->repo($kind)->find($id) ?? throw DomainError::notFound('Položka katalogu nenalezena.');
        if (!$isAdmin && $row['created_by_user_id'] !== $userId) {
            throw new DomainError('forbidden', 'Ikonu může změnit jen admin nebo autor položky.', 403);
        }
        return $row;
    }

    private function repo(string $kind): InstitutionRepository|InstrumentRepository
    {
        return match ($kind) {
            'institutions' => $this->institutions,
            'instruments'  => $this->instruments,
            default        => throw new \InvalidArgumentException("Neznámý druh katalogu: $kind"),
        };
    }
}

<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Appearance;

use MyPortfolio\Infrastructure\Database\Connection;

/**
 * Barevná schémata vzhledu: výchozí schéma instance (admin, `app_meta`) a volba uživatele (`users.color_scheme`, NULL =
 * schéma instance). Frontend má tokeny v `web/src/styles/schemes.css`, e-maily barvy z EMAIL a logo `logo-<schéma>.png`.
 */
final class ColorSchemes
{
    public const DEFAULT = 'forest';

    /** Pořadí podle odstínu — stejné jako výběr ve frontendu (`web/src/theme/colorSchemes.ts`). */
    public const KEYS = ['raspberry', 'terracotta', 'bronze', 'olive', 'forest', 'ocean', 'indigo', 'amethyst', 'orchid', 'graphite'];

    /** Světlé barvy pro e-mailové šablony (primary / action + neutral škála schématu). */
    public const EMAIL = [
        'raspberry' => ['accent' => '#BE123C', 'accent_soft' => '#FFF1F2', 'brand_text' => '#9F1239', 'bg' => '#F7F0F1', 'line' => '#EBE3E5', 'faint' => '#ADA2A5', 'muted' => '#75696D', 'secondary' => '#5C5155', 'body' => '#43393C', 'text' => '#171113'],
        'terracotta' => ['accent' => '#C2410C', 'accent_soft' => '#FFF7ED', 'brand_text' => '#9A3412', 'bg' => '#F6F1EE', 'line' => '#EAE4DF', 'faint' => '#ABA29B', 'muted' => '#736A63', 'secondary' => '#5A524C', 'body' => '#423B36', 'text' => '#16120F'],
        'bronze' => ['accent' => '#8A5E1E', 'accent_soft' => '#FBF7F0', 'brand_text' => '#6F4A17', 'bg' => '#F4F1EC', 'line' => '#E7E3DC', 'faint' => '#A8A198', 'muted' => '#706960', 'secondary' => '#57514A', 'body' => '#403B35', 'text' => '#15120F'],
        'olive' => ['accent' => '#65771F', 'accent_soft' => '#F7F9EC', 'brand_text' => '#4E5D18', 'bg' => '#F3F3EC', 'line' => '#E6E6DD', 'faint' => '#A6A69A', 'muted' => '#6E6E62', 'secondary' => '#55554B', 'body' => '#3E3E36', 'text' => '#141410'],
        'forest' => ['accent' => '#1B7138', 'accent_soft' => '#EFFAF0', 'brand_text' => '#115B2B', 'bg' => '#F0F5F1', 'line' => '#E3E8E3', 'faint' => '#A1A6A2', 'muted' => '#707470', 'secondary' => '#555956', 'body' => '#3D413D', 'text' => '#111512'],
        'ocean' => ['accent' => '#0369A1', 'accent_soft' => '#F0F9FF', 'brand_text' => '#075985', 'bg' => '#EEF4F9', 'line' => '#E1E8EF', 'faint' => '#A3ADB8', 'muted' => '#626D7A', 'secondary' => '#515C69', 'body' => '#3B4450', 'text' => '#111820'],
        'indigo' => ['accent' => '#4F46E5', 'accent_soft' => '#EEF2FF', 'brand_text' => '#4338CA', 'bg' => '#F0F1F8', 'line' => '#E3E5EF', 'faint' => '#A1A5B4', 'muted' => '#696D7C', 'secondary' => '#535665', 'body' => '#3B3E4A', 'text' => '#12131A'],
        'amethyst' => ['accent' => '#6D28D9', 'accent_soft' => '#F5F3FF', 'brand_text' => '#5B21B6', 'bg' => '#F2F0F8', 'line' => '#E6E3EF', 'faint' => '#A6A1B3', 'muted' => '#6E6A7A', 'secondary' => '#575363', 'body' => '#3E3B48', 'text' => '#14121A'],
        'orchid' => ['accent' => '#A21CAF', 'accent_soft' => '#FDF4FF', 'brand_text' => '#86198F', 'bg' => '#F6F0F6', 'line' => '#EAE3EA', 'faint' => '#ABA1AB', 'muted' => '#736A73', 'secondary' => '#5A515A', 'body' => '#423A42', 'text' => '#161116'],
        'graphite' => ['accent' => '#334155', 'accent_soft' => '#F8FAFC', 'brand_text' => '#334155', 'bg' => '#F1F1F2', 'line' => '#E4E4E7', 'faint' => '#A1A1AA', 'muted' => '#71717A', 'secondary' => '#52525B', 'body' => '#3F3F46', 'text' => '#18181B'],
    ];

    private const META_KEY = 'color_scheme';

    public function __construct(private readonly Connection $db) {}

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && in_array($key, self::KEYS, true);
    }

    /** Výchozí schéma instance; před migrací (setup wizard) výchozí aplikace. */
    public function instance(): string
    {
        try {
            $stmt = $this->db->pdo()->prepare('SELECT v FROM app_meta WHERE k = ?');
            $stmt->execute([self::META_KEY]);
            $key = $stmt->fetchColumn();
        } catch (\PDOException) {
            return self::DEFAULT;
        }
        return self::isValid($key) ? (string) $key : self::DEFAULT;
    }

    public function setInstance(string $key): void
    {
        $this->db->pdo()
            ->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([self::META_KEY, $key]);
    }

    /** Schéma, které uživatel vidí: vlastní volba, jinak schéma instance. */
    public function forUser(int $userId): string
    {
        $stmt = $this->db->pdo()->prepare('SELECT color_scheme FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $key = $stmt->fetchColumn();
        return self::isValid($key) ? (string) $key : $this->instance();
    }
}

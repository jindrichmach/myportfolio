<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Settings;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\Appearance\ColorSchemes;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET/PUT /api/settings/profile — vlastní profil přihlášeného uživatele.
 */
final class ProfileAction
{
    public const LOCALES = ['cs', 'en'];
    public const DISPLAY_CURRENCIES = ['CZK', 'EUR', 'USD'];

    public function __construct(private readonly Connection $db) {}

    public function get(Request $request, Response $response): Response
    {
        $profile = $this->load($this->userId($request));
        if ($profile === null) {
            return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        }
        return Json::ok($response, $profile);
    }

    public function update(Request $request, Response $response): Response
    {
        $userId = $this->userId($request);
        $body = (array) ($request->getParsedBody() ?? []);

        $sets = [];
        $params = [];
        $errors = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '' || mb_strlen($name) > 120) {
                $errors['name'][] = 'Jméno musí mít 1–120 znaků.';
            }
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if (array_key_exists('locale', $body)) {
            if (!in_array($body['locale'], self::LOCALES, true)) {
                $errors['locale'][] = 'Nepodporovaný jazyk.';
            }
            $sets[] = 'locale = ?';
            $params[] = (string) $body['locale'];
        }
        if (array_key_exists('display_currency', $body)) {
            if (!in_array($body['display_currency'], self::DISPLAY_CURRENCIES, true)) {
                $errors['display_currency'][] = 'Nepodporovaná měna.';
            }
            $sets[] = 'display_currency = ?';
            $params[] = (string) $body['display_currency'];
        }
        if (array_key_exists('color_scheme', $body)) {
            if ($body['color_scheme'] !== null && !ColorSchemes::isValid($body['color_scheme'])) {
                $errors['color_scheme'][] = 'Neznámé barevné schéma.';
            }
            $sets[] = 'color_scheme = ?';
            $params[] = $body['color_scheme'];
        }
        if ($errors !== []) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        if ($sets !== []) {
            $params[] = $userId;
            $this->db->pdo()
                ->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')
                ->execute($params);
        }

        return Json::ok($response, $this->load($userId));
    }

    private function userId(Request $request): int
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        return (int) ($user['id'] ?? 0);
    }

    /** @return array{name:string,email:string,locale:string,display_currency:string,color_scheme:?string}|null */
    private function load(int $userId): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT name, email, locale, display_currency, color_scheme FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : [
            'name'             => (string) $row['name'],
            'email'            => (string) $row['email'],
            'locale'           => (string) $row['locale'],
            'display_currency' => (string) $row['display_currency'],
            'color_scheme'     => $row['color_scheme'] !== null ? (string) $row['color_scheme'] : null,
        ];
    }
}

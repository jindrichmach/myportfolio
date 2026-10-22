<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Admin;

use MyPortfolio\Http\CurrentUser;
use MyPortfolio\Http\Json;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Repository\SmtpSettingsRepository;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Mail\SmtpSettings;
use MyPortfolio\Service\Mail\SmtpSettingsInput;
use MyPortfolio\Service\Portfolio\DomainError;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Nastavení SMTP z administrace:
 *   GET|PUT /api/admin/smtp       — nastavení (heslo se nevrací, jen `has_password`)
 *   POST    /api/admin/smtp/test  — zkušební e-mail s hodnotami formuláře (prázdné heslo = uložené)
 */
final class SmtpAction
{
    public function __construct(
        private readonly SmtpSettingsRepository $repo,
        private readonly SmtpSettings $smtp,
        private readonly SecretEncryption $secrets,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $activity,
    ) {}

    public function get(Request $request, Response $response): Response
    {
        $db = $this->repo->get();
        $cfg = $this->smtp->fromConfig();
        unset($cfg['password'], $cfg['source']);
        return Json::ok($response, [
            'settings'      => $db !== null ? self::present($db) : null,
            'active_source' => $db !== null && $db['enabled'] ? 'db' : 'cfg',
            'cfg'           => $cfg + ['has_password' => (string) $this->smtp->fromConfig()['password'] !== ''],
        ]);
    }

    public function update(Request $request, Response $response): Response
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $data = SmtpSettingsInput::parse($body);
            $password = (string) ($body['password'] ?? '');
            if ($data['auth_enabled'] && $password === '' && ($this->repo->get()['password_enc'] ?? null) === null) {
                throw DomainError::validation(['password' => ['Zadej heslo k SMTP serveru.']]);
            }
            $this->repo->save($data + ['password_enc' => $password !== '' ? $this->secrets->encrypt($password) : null], CurrentUser::id($request));
            $this->activity->log('smtp.updated', CurrentUser::id($request), 'smtp_settings', 1,
                ['host' => $data['host'], 'port' => $data['port'], 'enabled' => $data['enabled'], 'password_changed' => $password !== '']);
            return $this->get($request, $response);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }
    }

    public function test(Request $request, Response $response): Response
    {
        try {
            $body = (array) ($request->getParsedBody() ?? []);
            $data = SmtpSettingsInput::parse($body);
            $to = trim((string) ($body['to'] ?? ''));
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                throw DomainError::validation(['to' => ['Zadej platný e-mail příjemce.']]);
            }
            $password = (string) ($body['password'] ?? '');
            if ($password === '') {
                $stored = $this->repo->get()['password_enc'] ?? null;
                $password = $stored !== null ? $this->secrets->decrypt((string) $stored) : (string) $this->smtp->fromConfig()['password'];
            }
            $settings = SmtpSettingsInput::forTest($data, $password);
        } catch (DomainError $e) {
            return Json::domainError($response, $e);
        }

        try {
            $locale = (string) (((array) $request->getAttribute(AuthMiddleware::ATTR_USER, []))['locale'] ?? 'cs');
            $reply = $this->mailer->sendTest($settings, $to, $locale === 'en' ? 'en' : 'cs');
            $result = ['ok' => true, 'message' => $reply];
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }
        if ($this->repo->get() !== null) {
            $this->repo->recordTest($result['ok'] ? 'ok' : 'error', $result['message']);
        }
        $this->activity->log('smtp.tested', CurrentUser::id($request), 'smtp_settings', 1,
            ['host' => $data['host'], 'to' => $to, 'ok' => $result['ok']]);
        return Json::ok($response, $result);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        $row['has_password'] = $row['password_enc'] !== null;
        unset($row['password_enc']);
        return $row;
    }
}

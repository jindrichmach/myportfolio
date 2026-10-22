<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Mail;

use MyPortfolio\Support\Input;

/**
 * Validace formuláře SMTP — sdílí administrace (SmtpAction) a úvodní průvodce (SetupAction).
 */
final class SmtpSettingsInput
{
    private function __construct() {}

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> bez hesla
     * @throws \MyPortfolio\Service\Portfolio\DomainError validace
     */
    public static function parse(array $body): array
    {
        $errors = [];
        $data = [
            'enabled'        => Input::bool($body, 'enabled', true),
            'host'           => Input::string($body, 'host', 190, true, $errors),
            'port'           => (int) ($body['port'] ?? 587),
            'encryption'     => Input::enum($body, 'encryption', ['ssl', 'tls', 'none'], $errors, 'tls'),
            'verify_peer'    => Input::bool($body, 'verify_peer', true),
            'auth_enabled'   => Input::bool($body, 'auth_enabled', true),
            'username'       => Input::string($body, 'username', 190, false, $errors),
            'from_email'     => Input::string($body, 'from_email', 190, true, $errors),
            'from_name'      => Input::string($body, 'from_name', 120, false, $errors) ?? 'MyPortfolio',
            'reply_to_email' => Input::string($body, 'reply_to_email', 190, false, $errors),
            'reply_to_name'  => Input::string($body, 'reply_to_name', 120, false, $errors),
        ];
        if ($data['port'] < 1 || $data['port'] > 65535) {
            $errors['port'][] = 'Port musí být 1–65535.';
        }
        foreach (['from_email', 'reply_to_email'] as $k) {
            if ($data[$k] !== null && filter_var($data[$k], FILTER_VALIDATE_EMAIL) === false) {
                $errors[$k][] = 'Neplatný e-mail.';
            }
        }
        if ($data['auth_enabled'] && $data['username'] === null) {
            $errors['username'][] = 'Povinné pole.';
        }
        Input::assertValid($errors);
        return $data;
    }

    /**
     * Nastavení pro Mailer::sendTest() z vyplněného formuláře a hesla.
     *
     * @param array<string,mixed> $data výstup parse()
     * @return array<string,mixed>
     */
    public static function forTest(array $data, string $password): array
    {
        return $data + ['password' => $password, 'reply_to_email' => (string) $data['reply_to_email'], 'reply_to_name' => (string) $data['reply_to_name']];
    }
}

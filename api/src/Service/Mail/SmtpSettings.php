<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Mail;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Repository\SmtpSettingsRepository;
use MyPortfolio\Service\Auth\SecretEncryption;

/**
 * Výsledné nastavení SMTP: zapnuté nastavení z administrace (DB) má přednost před `cfg.php`.
 *
 * Tvar: host, port, encryption (ssl|tls|none), verify_peer, auth_enabled, username, password,
 * from_email, from_name, reply_to_email, reply_to_name, source (db|cfg).
 */
final class SmtpSettings
{
    public function __construct(
        private readonly Config $config,
        private readonly SmtpSettingsRepository $repo,
        private readonly SecretEncryption $secrets,
    ) {}

    /** @return array<string,mixed> */
    public function effective(): array
    {
        $db = $this->repo->get();
        if ($db !== null && $db['enabled']) {
            return [
                'host'           => (string) $db['host'],
                'port'           => (int) $db['port'],
                'encryption'     => (string) $db['encryption'],
                'verify_peer'    => (bool) $db['verify_peer'],
                'auth_enabled'   => (bool) $db['auth_enabled'],
                'username'       => (string) ($db['username'] ?? ''),
                'password'       => $db['password_enc'] !== null ? $this->secrets->decrypt((string) $db['password_enc']) : '',
                'from_email'     => (string) $db['from_email'],
                'from_name'      => (string) $db['from_name'],
                'reply_to_email' => (string) ($db['reply_to_email'] ?? ''),
                'reply_to_name'  => (string) ($db['reply_to_name'] ?? ''),
                'source'         => 'db',
            ];
        }
        return $this->fromConfig();
    }

    /** @return array<string,mixed> nastavení z cfg.php (zdroj, když v DB nic není) */
    public function fromConfig(): array
    {
        $enc = (string) $this->config->get('smtp.encryption', '');
        return [
            'host'           => (string) $this->config->get('smtp.host', ''),
            'port'           => (int) $this->config->get('smtp.port', 25),
            'encryption'     => in_array($enc, ['ssl', 'tls'], true) ? $enc : 'none',
            'verify_peer'    => (bool) $this->config->get('smtp.verify_peer', true),
            'auth_enabled'   => (bool) $this->config->get('smtp.auth_enabled', false),
            'username'       => (string) $this->config->get('smtp.user', ''),
            'password'       => (string) $this->config->get('smtp.pass', ''),
            'from_email'     => (string) $this->config->get('smtp.from_email', ''),
            'from_name'      => (string) $this->config->get('smtp.from_name', 'MyPortfolio'),
            'reply_to_email' => (string) $this->config->get('smtp.reply_to_email', ''),
            'reply_to_name'  => (string) $this->config->get('smtp.reply_to_name', ''),
            'source'         => 'cfg',
        ];
    }

    /**
     * Symfony Mailer DSN: ssl → smtps:// (implicitní TLS), tls → smtp:// s povinným STARTTLS
     * (require_tls — heslo nikdy neodejde nešifrovaně), none → bez TLS; verify_peer=0 jen na výslovné přání.
     *
     * @param array<string,mixed> $s
     */
    public static function dsn(array $s): string
    {
        $user = '';
        if (($s['auth_enabled'] ?? false) && ($s['username'] ?? '') !== '') {
            $user = rawurlencode((string) $s['username']) . ':' . rawurlencode((string) ($s['password'] ?? '')) . '@';
        }
        $query = [];
        if (($s['encryption'] ?? 'none') === 'none') {
            $query[] = 'auto_tls=false';
        } elseif ($s['encryption'] === 'tls') {
            $query[] = 'require_tls=true';
        }
        if (($s['encryption'] ?? 'none') === 'none' || !($s['verify_peer'] ?? true)) {
            $query[] = 'verify_peer=0';
        }
        return sprintf('%s://%s%s:%d%s', ($s['encryption'] ?? '') === 'ssl' ? 'smtps' : 'smtp', $user, $s['host'], (int) $s['port'],
            $query !== [] ? '?' . implode('&', $query) : '');
    }
}

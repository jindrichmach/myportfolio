<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Captcha;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use MyPortfolio\Infrastructure\Config\Config;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare Turnstile server-side ověření tokenu z `cf_turnstile_response`.
 *
 * - Pokud `cfg.captcha.provider != 'turnstile'`, vrací vždy true (no-op).
 * - Při výpadku Cloudflare API: rozhodne `cfg.captcha.fail_open`.
 * - Single-use token (Cloudflare ho po prvním verify zneplatní).
 */
final class TurnstileVerifier
{
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function verify(?string $token, string $clientIp, string $expectedAction = 'login'): bool
    {
        if ($this->config->get('captcha.provider', 'none') !== 'turnstile') {
            return true;
        }

        $secret = (string) $this->config->get('captcha.secret_key', '');
        if ($secret === '') {
            $this->logger->warning('Turnstile není nakonfigurované (chybí secret_key) — povoluju bez verify');
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        $url = (string) $this->config->get('captcha.verify_url', 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
        $timeout = (int) $this->config->get('captcha.timeout', 5);
        $failOpen = (bool) $this->config->get('captcha.fail_open', true);

        try {
            $client = new Client(['timeout' => $timeout, 'connect_timeout' => $timeout]);
            $resp = $client->post($url, [
                'form_params' => [
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => $clientIp,
                ],
            ]);
            $body = json_decode((string) $resp->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->warning('Turnstile API nedostupné: ' . $e->getMessage());
            return $failOpen;
        }

        if (!is_array($body)) {
            return $failOpen;
        }

        $success = !empty($body['success']);
        $action  = (string) ($body['action'] ?? '');

        if (!$success) {
            $this->logger->info('Turnstile token zamítnut', [
                'errors' => $body['error-codes'] ?? [],
                'action' => $action,
            ]);
            return false;
        }

        // Validace action — chrání proti replay z jiné stránky
        if ($action !== '' && $action !== $expectedAction) {
            $this->logger->warning('Turnstile action mismatch', [
                'expected' => $expectedAction,
                'got'      => $action,
            ]);
            return false;
        }

        return true;
    }
}

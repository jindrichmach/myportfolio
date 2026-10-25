<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\BruteForceGuard;
use MyPortfolio\Service\Captcha\TurnstileVerifier;
use MyPortfolio\Service\IpMatcher;
use MyPortfolio\Service\Mail\Mailer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Vždy vrací 204 (i pro neexistující email) → ochrana proti enumeration.
 * Token se generuje jen pokud user existuje, hash se uloží do password_resets.
 */
final class ForgotPasswordAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly Config $config,
        private readonly BruteForceGuard $bf,
        private readonly TurnstileVerifier $turnstile,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $email = trim((string) ($body['email'] ?? ''));
        $turnstileToken = (string) ($body['cf_turnstile_response'] ?? '');

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $userAgent = $request->getHeaderLine('User-Agent');

        // Rate limit přes BruteForceGuard (sdílený se /login)
        $state = $this->bf->check($email, $ip);
        if (in_array($state, [BruteForceGuard::STATE_LOCKED_15M, BruteForceGuard::STATE_LOCKED_24H], true)) {
            return Json::error($response, 'rate_limited', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }

        // Turnstile vždy aktivní — Cloudflare sám rozhoduje (auto-pass nebo interactive challenge).
        // No-op pokud captcha.provider != 'turnstile' nebo chybí secret_key (TurnstileVerifier).
        if (!$this->turnstile->verify($turnstileToken, $ip, 'forgot')) {
            $this->logger->log('auth.captcha_failed', null, null, null, [
                'email' => $email, 'ip' => $ip, 'flow' => 'forgot',
            ], $ip, $userAgent);
            $this->bf->recordFailure($email, $ip);
            return Json::error($response, 'captcha_failed', 'CAPTCHA selhala.', 400);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Pořád vrátíme 204
            return Json::ok($response, ['ok' => true], 204);
        }

        $stmt = $this->db->pdo()->prepare('SELECT id, email, name, locale FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            // Tichý exit
            $this->logger->log('auth.forgot_unknown', null, null, null, ['email' => $email], $ip, $request->getHeaderLine('User-Agent'));
            return Json::ok($response, ['ok' => true], 204);
        }

        // Invalidate předchozí (nepoužité, neexpirované) reset tokeny tohoto usera —
        // nový request musí starý link okamžitě vyřadit (i kdyby ho útočník odposlechl).
        $this->db->pdo()->prepare(
            'UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL'
        )->execute([(int) $user['id']]);

        // Vygeneruj token
        $tokenRaw = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $tokenRaw);
        $expiresAt = (new \DateTimeImmutable('+60 minutes'))->format('Y-m-d H:i:s');

        $this->db->pdo()->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip) VALUES (?, ?, ?, ?)'
        )->execute([(int) $user['id'], $tokenHash, $expiresAt, @inet_pton($ip) ?: '']);

        // Pošli email
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $resetLink = $appUrl . '/reset?token=' . $tokenRaw;

        try {
            $this->mailer->sendTemplate(
                'password_reset',
                (string) ($user['locale'] ?? 'cs'),
                [(string) $user['email']],
                [
                    'user_id'   => (int) $user['id'],
                    'name'      => $user['name'],
                    'resetLink' => $resetLink,
                    'expiresIn' => '60 minut',
                ],
            );
        } catch (\Throwable $e) {
            // Email se nepovedl, ale uživateli dál tváříme úspěch
            $this->logger->log('auth.forgot_mail_failed', (int) $user['id'], 'user', (int) $user['id'], [
                'error' => $e->getMessage(),
            ], $ip, $request->getHeaderLine('User-Agent'));
        }

        // Forgot rate-limit per email + per IP řeší RateLimitMiddleware (cfg.rate_limits.forgot_per_hour_per_email).
        // Dříve jsme zde volali recordFailure i při success — matoucí semantika, RateLimit teď pokrývá lépe.
        $this->logger->log('auth.forgot_sent', (int) $user['id'], 'user', (int) $user['id'], null, $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true], 204);
    }
}

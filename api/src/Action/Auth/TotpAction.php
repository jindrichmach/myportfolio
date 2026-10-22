<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\MfaPolicyService;
use MyPortfolio\Service\Auth\SessionAuthContext;
use MyPortfolio\Service\Auth\SessionCookieFactory;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\Auth\TotpService;
use MyPortfolio\Service\IpMatcher;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Self-service TOTP (2FA) endpointy pro přihlášeného uživatele:
 *   POST /api/auth/totp/setup   — vygeneruje secret + QR (pending — uloží do DB ale enabled=0)
 *   POST /api/auth/totp/enable  — ověří kód a flipne totp_enabled=1
 *   GET  /api/auth/totp/status  — vrátí { enabled: bool }
 *
 * Disable: CLI `php api/bin/reset-mfa.php <email>` (fallback ručně v DB).
 */
final class TotpAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly TotpService $totp,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly SecretEncryption $crypto,
        private readonly MfaPolicyService $mfaPolicy,
        private readonly SessionManager $sessions,
        private readonly SessionCookieFactory $sessionCookies,
        private readonly ClockInterface $clock,
    ) {}

    public function status(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);

        return Json::ok($response, [
            'enabled' => (bool) $user['totp_enabled'],
        ]);
    }

    public function setup(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        if (!$this->mfaPolicy->isMethodAllowed('totp')) {
            return Json::error($response, 'mfa_method_not_allowed', 'TOTP není v této instalaci povolené.', 403);
        }

        // Nový secret pokaždé — pokud už totp_enabled=1, vrať 409 (reset přes CLI)
        if ((int) $user['totp_enabled'] === 1) {
            return Json::error($response, 'already_enabled', 'TOTP už je aktivní. Pro reset použij: php api/bin/reset-mfa.php <email>.', 409);
        }

        $secret = TotpService::generateSecret();
        try {
            $encrypted = $this->crypto->encrypt($secret);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        // Šifrované AES-256-GCM v DB; do response zasíláme plain (jednorázově pro setup)
        $this->db->pdo()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?')
            ->execute([$encrypted, (int) $user['id']]);

        $issuer = parse_url((string) $this->config->get('app.url', 'MyPortfolio'), PHP_URL_HOST) ?: 'MyPortfolio';
        $uri = $this->totp->provisioningUri($secret, (string) $user['email'], $issuer);

        // QR kód jako data URI (PNG base64) — frontend vloží do <img src>
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel'        => EccLevel::M,
            'scale'           => 6,
            'imageBase64'     => true,
            'quietzoneSize'   => 2,
        ]);
        $qrDataUri = (new QRCode($options))->render($uri);

        return Json::ok($response, [
            'secret'      => $secret,        // pro manuální vložení do app
            'uri'         => $uri,
            'qr_data_uri' => $qrDataUri,
            'issuer'      => $issuer,
        ]);
    }

    public function enable(Request $request, Response $response): Response
    {
        $user = $this->user($request);
        if ($user === null) return Json::error($response, 'unauthenticated', 'Nepřihlášený uživatel.', 401);
        if (!$this->mfaPolicy->isMethodAllowed('totp')) {
            return Json::error($response, 'mfa_method_not_allowed', 'TOTP není v této instalaci povolené.', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') {
            return Json::error($response, 'validation_failed', 'Chybí kód.', 400);
        }
        if (empty($user['totp_secret'])) {
            return Json::error($response, 'no_secret', 'Nejdřív zavolej /setup pro vygenerování secretu.', 400);
        }
        try {
            $secret = $this->crypto->decrypt((string) $user['totp_secret']);
        } catch (\RuntimeException) {
            return Json::error($response, 'server_error', 'Chyba konfigurace serveru.', 500);
        }
        if (!$this->totp->verify($secret, $code)) {
            return Json::error($response, 'invalid_code', 'Neplatný TOTP kód.', 400);
        }

        $this->db->pdo()->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?')
            ->execute([(int) $user['id']]);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.totp_enabled', (int) $user['id'], 'user', (int) $user['id'], null, $ip, $request->getHeaderLine('User-Agent'));

        $session = $request->getAttribute(AuthMiddleware::ATTR_SESSION);
        if (!is_array($session) || ($session['assurance_level'] ?? 'legacy') !== 'setup') {
            return Json::ok($response, ['enabled' => true]);
        }
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        try {
            $completed = $this->sessions->completeSetup(
                (int) $user['id'],
                $token,
                $ip,
                $request->getHeaderLine('User-Agent'),
                SessionAuthContext::strong('totp', $this->clock->now()),
            );
        } catch (\DomainException) {
            return Json::error($response, 'session_expired', 'Setup session vypršela.', 401);
        }

        return Json::ok($response, [
            'enabled' => true,
            'csrf_token' => $completed['csrf_token'],
            'session_state' => 'active',
            'must_setup_mfa' => false,
        ])->withHeader('Set-Cookie', $this->sessionCookies->create(
            $completed['token'],
            $completed['expires_at'],
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function user(Request $request): ?array
    {
        $u = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (empty($u)) return null;
        // Načti čerstvý záznam (auth middleware nedává totp_*)
        $stmt = $this->db->pdo()->prepare('SELECT id, email, totp_secret, totp_enabled FROM users WHERE id = ?');
        $stmt->execute([(int) $u['id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

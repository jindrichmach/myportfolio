<?php

declare(strict_types=1);

namespace MyPortfolio\Middleware;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Cache\RedisFactory;
use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Sliding-window rate limiter dle cfg.rate_limits.
 *
 *   login_per_min_per_ip      → 60s window, key = "rl:login:ip:{ip/24}"
 *   forgot_per_hour_per_email → 3600s window, key = "rl:forgot:email:{sha1}"
 *   setup_per_hour_per_ip     → 3600s window, key = "rl:setup:ip:{ip}"
 *   mutation_per_min_per_user → 60s window, key = "rl:mut:user:{id}" (POST/PUT/PATCH/DELETE)
 *   read_per_min_per_user     → 60s window, key = "rl:read:user:{id}" (GET)
 *
 * Při překročení vrátí 429 Too Many Requests s Retry-After.
 *
 * Vyžaduje Redis. Bez Redis je rate limit no-op (BruteForceGuard pokrývá login).
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly RedisFactory $redis,
        private readonly ResponseFactory $responseFactory,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $r = $this->redis->client();
        if ($r === null) {
            return $handler->handle($request); // bez Redis no-op
        }

        $path = $request->getUri()->getPath();
        $method = strtoupper($request->getMethod());
        $ip = $this->ipMatcher->clientIp(
            $request->getServerParams(),
            (array) $this->config->get('ip_allowlist.trusted_proxies', []),
            (string) $this->config->get('ip_allowlist.header', 'X-Forwarded-For'),
        );

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        $apiToken = $request->getAttribute(AuthMiddleware::ATTR_API_TOKEN);
        $tokenId = is_array($apiToken) ? (int) ($apiToken['id'] ?? 0) : 0;

        // Per-route limity — vrací [key, limit, window]
        $rule = $this->ruleFor($path, $method, $ip, $userId, $tokenId, $request);
        if ($rule === null) {
            return $handler->handle($request);
        }

        [$key, $limit, $window] = $rule;
        $count = (int) ($r->get($key) ?? 0);
        $ttl = (int) $r->ttl($key);
        if ($ttl < 0) $ttl = $window;  // -1/-2 = neexistuje nebo bez TTL

        // Headers se posílají u všech bearer-authed requestů (rfc draft-rate-limit
        // používá X-RateLimit-*). U session/IP requestů nemá smysl — klient
        // nemůže selektivně self-throttle per uživatel.
        $sendHeaders = $tokenId > 0;
        $remaining = max(0, $limit - $count);

        if ($count >= $limit) {
            $response = $this->responseFactory->createResponse(429);
            $response = $response->withHeader('Retry-After', (string) max(1, $ttl));
            if ($sendHeaders) {
                $response = $response
                    ->withHeader('X-RateLimit-Limit',     (string) $limit)
                    ->withHeader('X-RateLimit-Remaining', '0')
                    ->withHeader('X-RateLimit-Reset',     (string) max(1, $ttl));
            }
            return Json::error($response, 'rate_limited', 'Příliš mnoho pokusů. Zkus to později.', 429);
        }

        // Increment + set expiry (idempotentně)
        $r->incr($key);
        $r->expire($key, $window);

        $response = $handler->handle($request);
        if ($sendHeaders) {
            $response = $response
                ->withHeader('X-RateLimit-Limit',     (string) $limit)
                ->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining - 1))
                ->withHeader('X-RateLimit-Reset',     (string) max(1, $ttl));
        }
        return $response;
    }

    /**
     * @return array{0:string,1:int,2:int}|null  [redisKey, limit, windowSeconds]
     */
    private function ruleFor(string $path, string $method, string $ip, int $userId, int $tokenId, Request $request): ?array
    {
        $rl = (array) $this->config->get('rate_limits', []);

        // Bearer (API token) — vlastní bucket per token, ne per user (jeden user
        // může mít víc tokenů pro různé integrace s nezávislými limity).
        if ($tokenId > 0) {
            $limit = (int) ($rl['api_per_min_per_token'] ?? 600);
            return ['rl:api:tok:' . $tokenId, $limit, 60];
        }

        // Login — všichni
        if ($path === '/api/auth/login' && $method === 'POST') {
            return ['rl:login:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }
        if ($path === '/api/auth/webauthn/login/verify' && $method === 'POST') {
            return ['rl:passkey-login:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }
        if ($path === '/api/auth/webauthn/login/options' && $method === 'POST') {
            return ['rl:passkey-login-options:ip:' . $this->ipBucket($ip), (int) ($rl['login_per_min_per_ip'] ?? 10), 60];
        }

        if ($userId > 0 && str_starts_with($path, '/api/auth/session/')) {
            if (in_array($path, [
                '/api/auth/session/status',
                '/api/auth/session/activity',
            ], true)) {
                $sessionToken = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
                $subject = $sessionToken !== ''
                    ? 'session:' . hash('sha256', $sessionToken)
                    : 'user:' . $userId;
                return ['rl:session-poll:' . sha1($path) . ':' . $subject, 120, 60];
            }
            return ['rl:session:' . sha1($path) . ':user:' . $userId, 20, 60];
        }
        if ($userId > 0 && (
            str_starts_with($path, '/api/auth/webauthn/')
            || str_starts_with($path, '/api/auth/mfa/step-up/')
        )) {
            return ['rl:webauthn:' . sha1($path) . ':user:' . $userId, 20, 60];
        }

        // Forgot — per email
        if ($path === '/api/auth/forgot' && $method === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);
            $email = strtolower(trim((string) ($body['email'] ?? '')));
            if ($email !== '') {
                return ['rl:forgot:email:' . sha1($email), (int) ($rl['forgot_per_hour_per_email'] ?? 3), 3600];
            }
        }

        // Setup
        if ($path === '/api/auth/setup/smtp-test' && $method === 'POST') {
            return ['rl:setup-smtp:ip:' . $this->ipBucket($ip), 20, 3600];
        }
        if ($path === '/api/auth/setup' && $method === 'POST') {
            return ['rl:setup:ip:' . $this->ipBucket($ip), (int) ($rl['setup_per_hour_per_ip'] ?? 5), 3600];
        }

        // Generic per-user mutation/read limit (jen pro přihlášené, mimo public)
        if ($userId > 0 && !str_starts_with($path, '/api/auth/')) {
            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['rl:mut:user:' . $userId, (int) ($rl['mutation_per_min_per_user'] ?? 60), 60];
            }
            if ($method === 'GET') {
                return ['rl:read:user:' . $userId, (int) ($rl['read_per_min_per_user'] ?? 300), 60];
            }
        }

        return null;
    }

    private function ipBucket(string $ip): string
    {
        $packed = inet_pton($ip);
        if ($packed === false) return sha1($ip);
        if (strlen($packed) === 4) return bin2hex(substr($packed, 0, 3)); // /24
        return bin2hex(substr($packed, 0, 8)); // /64
    }
}

<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Middleware\AuthMiddleware;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ChangePasswordAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly PasswordHasher $hasher,
        private readonly SessionManager $sessions,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $token = (string) $request->getAttribute(AuthMiddleware::ATTR_TOKEN, '');
        $userId = (int) ($user['id'] ?? 0);

        $body = (array) ($request->getParsedBody() ?? []);
        $current = (string) ($body['current_password'] ?? '');
        $new = (string) ($body['new_password'] ?? '');
        $confirm = (string) ($body['new_password_confirm'] ?? '');

        if ($new !== $confirm) {
            return Json::error($response, 'validation_failed', 'Nová hesla se neshodují.', 400);
        }

        // Ověř current
        $stmt = $this->db->pdo()->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || !$this->hasher->verify($current, (string) $row['password_hash'])) {
            return Json::error($response, 'invalid_current_password', 'Aktuální heslo není správné.', 400);
        }

        try {
            $newHash = $this->hasher->hash($new);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $this->db->pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$newHash, $userId]);

        // Invaliduj všechny ostatní sessions kromě této
        $invalidated = $this->sessions->destroyAllForUser($userId, $token);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.password_changed', $userId, 'user', $userId, [
            'sessions_invalidated' => $invalidated,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true, 'sessions_invalidated' => $invalidated]);
    }
}

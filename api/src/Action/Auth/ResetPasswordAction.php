<?php

declare(strict_types=1);

namespace MyPortfolio\Action\Auth;

use MyPortfolio\Http\Json;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\ActivityLogger;
use MyPortfolio\Service\Auth\PasswordHasher;
use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ResetPasswordAction
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
        $body = (array) ($request->getParsedBody() ?? []);
        $tokenRaw = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $confirm  = (string) ($body['password_confirm'] ?? '');

        if ($tokenRaw === '' || $password === '') {
            return Json::error($response, 'invalid_token', 'Token nebo heslo chybí.', 400);
        }
        if ($password !== $confirm) {
            return Json::error($response, 'validation_failed', 'Hesla se neshodují.', 400);
        }

        $tokenHash = hash('sha256', $tokenRaw);
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, user_id, expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return Json::error($response, 'invalid_token', 'Neplatný token.', 400);
        }
        if ($row['used_at'] !== null) {
            return Json::error($response, 'token_already_used', 'Token už byl použit.', 410);
        }
        if (strtotime((string) $row['expires_at']) < time()) {
            return Json::error($response, 'token_expired', 'Platnost tokenu vypršela.', 410);
        }

        try {
            $hash = $this->hasher->hash($password);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'validation_failed', $e->getMessage(), 400);
        }

        $userId = (int) $row['user_id'];

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ?')->execute([(int) $row['id']]);
            $pdo->prepare('DELETE FROM trusted_devices WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM login_otps WHERE user_id = ?')->execute([$userId]);
            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            return Json::error($response, 'reset_failed', $e->getMessage(), 500);
        }

        // Invaliduj všechny aktivní sessions
        $invalidated = $this->sessions->destroyAllForUser($userId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('auth.password_reset', $userId, 'user', $userId, [
            'sessions_invalidated' => $invalidated,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, ['ok' => true]);
    }
}

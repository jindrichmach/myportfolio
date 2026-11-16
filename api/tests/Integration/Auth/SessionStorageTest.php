<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Auth;

use MyPortfolio\Service\Auth\SessionManager;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Token z cookie se v DB neukládá — únik tabulky sessions nedá platnou session. */
#[Group('integration')]
final class SessionStorageTest extends ApiTestCase
{
    public function testDatabaseHoldsOnlyTokenHash(): void
    {
        $sessions = $this->container->get(SessionManager::class);
        $created = $sessions->create($this->userId, '127.0.0.1', 'PHPUnit');
        $token = (string) $created['token'];

        $count = $this->db->pdo()->prepare('SELECT COUNT(*) FROM sessions WHERE id = ?');
        $count->execute([$token]);
        self::assertSame(0, (int) $count->fetchColumn());
        $count->execute([hash('sha256', $token)]);
        self::assertSame(1, (int) $count->fetchColumn());

        self::assertSame($this->userId, $sessions->load($token)['user_id'] ?? null);
        self::assertNull($sessions->load(hash('sha256', $token)), 'hash z DB jako cookie neprojde');
        self::assertSame(200, $this->call($this->userId, 'GET', '/api/auth/me')['status']);

        $sessions->destroy($token);
        self::assertNull($sessions->load($token));
    }
}

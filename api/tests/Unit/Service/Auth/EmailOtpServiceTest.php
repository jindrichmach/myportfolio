<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Unit\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Infrastructure\Database\Connection;
use MyPortfolio\Service\Auth\EmailOtpService;
use MyPortfolio\Service\Mail\Mailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Vyžaduje běžící MariaDB s konfigurací v cfg.php — používá ostrou DB
 * (login_otps prvního usera), proto si po sobě uklízí. Mailer je mocknutý,
 * takže žádné e-maily neodcházejí; zachycujeme z něj jen vygenerovaný kód.
 */
#[Group('integration')]
final class EmailOtpServiceTest extends TestCase
{
    private Connection $db;
    private Config $config;
    private EmailOtpService $svc;
    private int $userId = 0;
    private array $user = [];

    /** Poslední kód, který by býval odešel e-mailem (zachycený z mocku). */
    private ?string $lastCode = null;

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 5);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php missing — skip live-DB test.');
        }
        try {
            $this->config = Config::load($rootDir);
            $this->db = new Connection($this->config);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DB unavailable: ' . $e->getMessage());
        }

        $this->userId = (int) $this->db->pdo()->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->userId <= 0) {
            $this->markTestSkipped('No users in DB — skip.');
        }
        $this->user = [
            'id'     => $this->userId,
            'email'  => '__test_otp@example.com',
            'name'   => 'Test',
            'locale' => 'cs',
        ];

        // Mailer stub — BypassFinals (bootstrap) umožní double-ovat final třídu.
        // sendTemplate je volaný s ($code, $locale, $to, $vars) → vars['code'].
        // createStub (ne createMock) — nemáme na něj expektace, jen zachytáváme kód.
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTemplate')->willReturnCallback(
            function (string $code, string $locale, array $to, array $vars): string {
                $this->lastCode = (string) ($vars['code'] ?? '');
                return 'ok';
            }
        );

        $this->svc = new EmailOtpService($this->db, $this->config, $mailer, new NullLogger());
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->userId > 0) {
            $this->cleanup();
        }
        if (isset($this->db)) $this->db->close(); // uvolni MySQL spojení (kumulace → max_connections)
    }

    private function cleanup(): void
    {
        $this->db->pdo()->prepare('DELETE FROM login_otps WHERE user_id = ?')->execute([$this->userId]);
    }

    public function testIssueSendsCodeAndVerifySucceeds(): void
    {
        $res = $this->svc->issue($this->user, '127.0.0.1');
        self::assertTrue($res['sent'], 'První issue musí poslat nový kód.');
        self::assertNotNull($this->lastCode);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $this->lastCode);

        self::assertTrue($this->svc->verify($this->userId, (string) $this->lastCode), 'Správný kód musí projít.');
    }

    public function testCodeIsStoredHashedNeverPlaintext(): void
    {
        $this->svc->issue($this->user, '127.0.0.1');
        $row = $this->db->pdo()->prepare('SELECT code_hash FROM login_otps WHERE user_id = ? ORDER BY id DESC LIMIT 1');
        $row->execute([$this->userId]);
        $hash = (string) $row->fetchColumn();
        self::assertSame(64, strlen($hash), 'V DB musí být sha256 hex (64 znaků), ne plaintext.');
        self::assertSame(hash('sha256', (string) $this->lastCode), $hash);
        self::assertStringNotContainsString((string) $this->lastCode, $hash);
    }

    public function testWrongCodeFails(): void
    {
        $this->svc->issue($this->user, '127.0.0.1');
        $wrong = $this->lastCode === '000000' ? '111111' : '000000';
        self::assertFalse($this->svc->verify($this->userId, $wrong));
    }

    public function testCodeIsOneTimeUse(): void
    {
        $this->svc->issue($this->user, '127.0.0.1');
        $code = (string) $this->lastCode;
        self::assertTrue($this->svc->verify($this->userId, $code));
        self::assertFalse($this->svc->verify($this->userId, $code), 'Použitý kód už nesmí projít podruhé.');
    }

    public function testNonNumericCodeFails(): void
    {
        $this->svc->issue($this->user, '127.0.0.1');
        self::assertFalse($this->svc->verify($this->userId, 'abcdef'));
        self::assertFalse($this->svc->verify($this->userId, ''));
    }

    public function testIssueReusesActiveCodeWithoutResending(): void
    {
        $first = $this->svc->issue($this->user, '127.0.0.1');
        self::assertTrue($first['sent']);
        $firstCode = (string) $this->lastCode;
        $this->lastCode = null;

        // Bez force: aktivní kód existuje → neposílat znovu.
        $second = $this->svc->issue($this->user, '127.0.0.1');
        self::assertFalse($second['sent'], 'Aktivní kód existuje → druhý issue nesmí poslat nový.');
        self::assertNull($this->lastCode, 'Mailer se nesmí znovu zavolat.');

        // Původní kód pořád platí.
        self::assertTrue($this->svc->verify($this->userId, $firstCode));
    }

    public function testResendRespectsCooldown(): void
    {
        $cooldown = $this->svc->resendCooldownSeconds();
        if ($cooldown <= 0) {
            $this->markTestSkipped('resend cooldown vypnutý — test nedává smysl.');
        }
        $this->svc->issue($this->user, '127.0.0.1');
        $this->lastCode = null;

        // force=true, ale cooldown ještě neuplynul → neposílat.
        $res = $this->svc->issue($this->user, '127.0.0.1', true);
        self::assertFalse($res['sent'], 'Resend během cooldownu nesmí poslat nový kód.');
        self::assertGreaterThan(0, $res['cooldown_remaining']);
        self::assertNull($this->lastCode);
    }

    public function testMaxAttemptsInvalidatesCode(): void
    {
        $this->svc->issue($this->user, '127.0.0.1');
        $code = (string) $this->lastCode;
        $wrong = $code === '000000' ? '111111' : '000000';

        $max = $this->svc->maxAttempts();
        for ($i = 0; $i < $max; $i++) {
            self::assertFalse($this->svc->verify($this->userId, $wrong));
        }
        // Po vyčerpání pokusů je kód zneplatněný — i správný kód musí selhat.
        self::assertFalse($this->svc->verify($this->userId, $code), 'Po max pokusech je kód neplatný i pro správnou hodnotu.');
    }

    public function testExpiredCodeFails(): void
    {
        $code = '654321';
        $this->db->pdo()->prepare(
            'INSERT INTO login_otps (user_id, code_hash, expires_at, ip) VALUES (?, ?, NOW() - INTERVAL 1 MINUTE, ?)'
        )->execute([$this->userId, hash('sha256', $code), '']);

        self::assertFalse($this->svc->verify($this->userId, $code), 'Expirovaný kód nesmí projít.');
    }
}

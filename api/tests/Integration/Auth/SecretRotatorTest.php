<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Auth;

use MyPortfolio\Infrastructure\Config\Config;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Auth\SecretRotator;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Převod tajemství ze staršího formátu (nešifrovaný text) na enc:v2 s AAD — jako v migrate.php. */
#[Group('integration')]
final class SecretRotatorTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->prepare('UPDATE users SET totp_secret = NULL WHERE id IN (?, ?)')->execute([$this->userId, $this->adminId]);
        }
        parent::tearDown();
    }

    public function testLegacyValuesAreReencryptedOnce(): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE users SET totp_secret = 'JBSWY3DPEHPK3PXP' WHERE id = ?")->execute([$this->userId]);
        $secrets = $this->container->get(SecretEncryption::class);
        $current = $secrets->encrypt('ADMINSECRET', SecretEncryption::context('users.totp_secret', $this->adminId));
        $pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')->execute([$current, $this->adminId]);

        $rotator = $this->container->get(SecretRotator::class);
        self::assertSame(1, $rotator->rotate(dryRun: true)['users.totp_secret']['rotated']);
        self::assertSame(1, $rotator->rotate()['users.totp_secret']['rotated'], 'aktuální hodnota admina se nepřešifruje');

        $stored = (string) $pdo->query("SELECT totp_secret FROM users WHERE id = {$this->userId}")->fetchColumn();
        self::assertStringStartsWith('enc:v2:', $stored);
        self::assertSame('JBSWY3DPEHPK3PXP', $secrets->decrypt($stored, SecretEncryption::context('users.totp_secret', $this->userId)));
        self::assertSame($current, $pdo->query("SELECT totp_secret FROM users WHERE id = {$this->adminId}")->fetchColumn());
        self::assertSame(0, $rotator->rotate()['users.totp_secret']['rotated']);
    }

    public function testKeyRotationKeepsValuesReadable(): void
    {
        $config = $this->container->get(Config::class);
        $oldKey = (string) $config->get('app.secret_encryption_key');
        $ctx = SecretEncryption::context('users.totp_secret', $this->userId);
        $pdo = $this->db->pdo();
        $pdo->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')
            ->execute([(new SecretEncryption($config))->encrypt('ROTATEME', $ctx), $this->userId]);

        $newKey = base64_encode(random_bytes(32));
        $rotated = new SecretEncryption(new Config(['app' => ['secret_encryption_key' => $newKey,
            'secret_encryption_previous_keys' => [$oldKey], 'env' => 'production']]));
        try {
            $result = (new SecretRotator($this->db, $rotated))->rotate();

            self::assertSame([], $result['users.totp_secret']['failed']);
            $stored = (string) $pdo->query("SELECT totp_secret FROM users WHERE id = {$this->userId}")->fetchColumn();
            self::assertSame('ROTATEME', $rotated->decrypt($stored, $ctx));
        } finally {
            // zbytek testovací DB (např. smtp_settings) zpět na klíč z konfigurace
            (new SecretRotator($this->db, new SecretEncryption(new Config(['app' => ['secret_encryption_key' => $oldKey,
                'secret_encryption_previous_keys' => [$newKey], 'env' => 'production']]))))->rotate();
        }
    }
}

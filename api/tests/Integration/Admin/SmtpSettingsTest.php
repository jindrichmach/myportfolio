<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Admin;

use DI\Container;
use MyPortfolio\Repository\SmtpSettingsRepository;
use MyPortfolio\Service\Auth\SecretEncryption;
use MyPortfolio\Service\Mail\Mailer;
use MyPortfolio\Service\Mail\SmtpSettings;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class SmtpSettingsTest extends ApiTestCase
{
    private const FORM = [
        'enabled' => true, 'host' => 'smtp.example.invalid', 'port' => 587, 'encryption' => 'tls', 'verify_peer' => true,
        'auth_enabled' => true, 'username' => 'mailer@example.invalid', 'password' => 'synthetic-smtp-secret',
        'from_email' => 'noreply@example.invalid', 'from_name' => 'MyPortfolio Test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->pdo()->exec('DELETE FROM smtp_settings');
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->exec('DELETE FROM smtp_settings');
        }
        parent::tearDown();
    }

    public function testSaveNeverReturnsPasswordAndDbOverridesConfig(): void
    {
        $before = $this->call($this->adminId, 'GET', '/api/admin/smtp');
        self::assertSame(200, $before['status']);
        self::assertSame(['settings' => null, 'active_source' => 'cfg'], array_intersect_key($before['body'], ['settings' => 1, 'active_source' => 1]));
        self::assertArrayNotHasKey('password', $before['body']['cfg']);

        $saved = $this->call($this->adminId, 'PUT', '/api/admin/smtp', self::FORM);
        self::assertSame(200, $saved['status'], json_encode($saved['body']) ?: '');
        self::assertSame('db', $saved['body']['active_source']);
        self::assertTrue($saved['body']['settings']['has_password']);
        self::assertStringNotContainsString('synthetic-smtp-secret', $saved['raw']);

        $effective = $this->container->get(SmtpSettings::class)->effective();
        self::assertSame(['db', 'smtp.example.invalid', 'synthetic-smtp-secret'], [$effective['source'], $effective['host'], $effective['password']]);
        self::assertStringNotContainsString('synthetic-smtp-secret', (string) $this->container->get(SmtpSettingsRepository::class)->get()['password_enc']);

        // Prázdné heslo = beze změny; vypnuté nastavení → cfg.php.
        $off = $this->call($this->adminId, 'PUT', '/api/admin/smtp', array_merge(self::FORM, ['password' => '', 'enabled' => false]));
        self::assertSame(['cfg', true], [$off['body']['active_source'], $off['body']['settings']['has_password']]);
        self::assertSame('cfg', $this->container->get(SmtpSettings::class)->effective()['source']);

        $bad = $this->call($this->adminId, 'PUT', '/api/admin/smtp', array_merge(self::FORM, ['port' => 0, 'from_email' => 'x', 'encryption' => 'rot13']));
        self::assertSame(400, $bad['status']);
        self::assertEqualsCanonicalizing(['port', 'from_email', 'encryption'], array_keys($bad['body']['error']['fields']));

        self::assertSame(403, $this->call($this->userId, 'GET', '/api/admin/smtp')['status']);
        self::assertSame(403, $this->call($this->userId, 'PUT', '/api/admin/smtp', self::FORM)['status']);
    }

    public function testTestSendsWithFormValuesAndStoredPassword(): void
    {
        $form = self::FORM;
        unset($form['password']);
        $this->container->get(SmtpSettingsRepository::class)->save($form + ['reply_to_email' => null, 'reply_to_name' => null,
            'password_enc' => $this->container->get(SecretEncryption::class)->encrypt('synthetic-smtp-secret')], $this->adminId);
        $calls = [];
        $mailer = $this->createStub(Mailer::class);
        $mailer->method('sendTest')->willReturnCallback(function (array $settings, string $to) use (&$calls): string {
            $calls[] = [$settings['host'], $settings['password'], $to];
            if ($settings['host'] === 'down.example.invalid') {
                throw new \RuntimeException('Connection refused');
            }
            return '250 2.0.0 Ok: queued';
        });
        $container = $this->container;
        self::assertInstanceOf(Container::class, $container);
        $container->set(Mailer::class, $mailer);

        $ok = $this->call($this->adminId, 'POST', '/api/admin/smtp/test', array_merge(self::FORM, ['password' => '', 'to' => 'admin@example.invalid']));
        self::assertSame(['ok' => true, 'message' => '250 2.0.0 Ok: queued'], $ok['body']);
        self::assertSame(['smtp.example.invalid', 'synthetic-smtp-secret', 'admin@example.invalid'], $calls[0]);

        $fail = $this->call($this->adminId, 'POST', '/api/admin/smtp/test', array_merge(self::FORM, ['host' => 'down.example.invalid', 'to' => 'admin@example.invalid']));
        self::assertSame(['ok' => false, 'message' => 'Connection refused'], $fail['body']);
        $row = $this->container->get(SmtpSettingsRepository::class)->get();
        self::assertSame(['error', 'Connection refused'], [$row['last_test_status'], $row['last_test_message']]);

        self::assertSame(400, $this->call($this->adminId, 'POST', '/api/admin/smtp/test', self::FORM + ['to' => 'nope'])['status']);
        self::assertSame(403, $this->call($this->userId, 'POST', '/api/admin/smtp/test', self::FORM + ['to' => 'a@example.invalid'])['status']);
    }
}

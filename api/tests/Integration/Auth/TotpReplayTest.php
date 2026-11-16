<?php

declare(strict_types=1);

namespace MyPortfolio\Tests\Integration\Auth;

use MyPortfolio\Service\Auth\TotpService;
use MyPortfolio\Service\Auth\TotpVerifier;
use MyPortfolio\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * TOTP kód jde použít jen jednou (RFC 6238 §5.2) — ani znovu, ani starší kód z okna ±30 s.
 */
#[Group('integration')]
final class TotpReplayTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->pdo()->prepare('UPDATE users SET totp_last_step = NULL WHERE id = ?')->execute([$this->userId]);
        }
        parent::tearDown();
    }

    public function testCodeIsAcceptedOnlyOnce(): void
    {
        $totp = new TotpService();
        $verifier = $this->container->get(TotpVerifier::class);
        $secret = TotpService::generateSecret();
        $code = $totp->currentCode($secret);

        self::assertTrue($verifier->verify($this->userId, $secret, $code));
        self::assertFalse($verifier->verify($this->userId, $secret, $code), 'stejný kód podruhé');

        // Po přijetí aktuálního kroku neprojde ani kód z předchozího kroku (stále v okně ±1)
        $this->db->pdo()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')
            ->execute([(int) floor(time() / 30), $this->userId]);
        $previous = (new \ReflectionMethod($totp, 'generateAt'))->invoke($totp, $secret, (int) floor(time() / 30) - 1);
        self::assertFalse($verifier->verify($this->userId, $secret, $previous));
    }

    public function testOtherUserIsIndependent(): void
    {
        $totp = new TotpService();
        $verifier = $this->container->get(TotpVerifier::class);
        $secret = TotpService::generateSecret();
        $code = $totp->currentCode($secret);

        self::assertTrue($verifier->verify($this->userId, $secret, $code));
        try {
            self::assertTrue($verifier->verify($this->adminId, $secret, $code));
        } finally {
            $this->db->pdo()->prepare('UPDATE users SET totp_last_step = NULL WHERE id = ?')->execute([$this->adminId]);
        }
    }
}

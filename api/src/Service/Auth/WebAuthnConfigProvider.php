<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use MyPortfolio\Infrastructure\Config\Config;

/**
 * WebAuthn konfiguraci validuje lazy. Chybná canonical URL tak znepřístupní
 * pouze passkey operace, ne heslo/TOTP login ani běžný session status.
 */
final class WebAuthnConfigProvider
{
    private bool $resolved = false;
    private ?WebAuthnConfig $webAuthn = null;
    private ?string $error = null;

    public function __construct(private readonly Config $config) {}

    public function isAvailable(): bool
    {
        $this->resolve();
        return $this->webAuthn !== null;
    }

    public function get(): WebAuthnConfig
    {
        $this->resolve();
        if ($this->webAuthn === null) {
            throw new \DomainException('Passkeys nejsou kvůli konfiguraci této instalace dostupné.');
        }
        return $this->webAuthn;
    }

    public function validationError(): ?string
    {
        $this->resolve();
        return $this->error;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        try {
            $this->webAuthn = new WebAuthnConfig($this->config);
        } catch (\InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }
}

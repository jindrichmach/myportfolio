<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

final readonly class WebAuthnCeremony
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public string $purpose,
        public ?int $userId,
        public ?string $operation,
        public string $challenge,
        public array $options,
    ) {}
}

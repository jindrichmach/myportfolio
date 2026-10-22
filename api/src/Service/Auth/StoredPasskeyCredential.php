<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

use Webauthn\CredentialRecord;

final readonly class StoredPasskeyCredential
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $label,
        public CredentialRecord $record,
        public ?string $createdAt,
        public ?string $lastUsedAt,
        public ?string $revokedAt,
    ) {}
}

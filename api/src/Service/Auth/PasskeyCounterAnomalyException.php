<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

final class PasskeyCounterAnomalyException extends PasskeyVerificationException
{
    public function __construct(public readonly int $credentialId)
    {
        parent::__construct('Ověření passkey selhalo.');
    }
}

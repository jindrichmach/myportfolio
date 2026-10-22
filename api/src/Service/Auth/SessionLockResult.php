<?php

declare(strict_types=1);

namespace MyPortfolio\Service\Auth;

final readonly class SessionLockResult
{
    private function __construct(
        public bool $sessionExists,
        public bool $locked,
        public bool $transitioned,
        public ?string $reason,
        public ?\DateTimeImmutable $lastUserActivityAt,
        public ?\DateTimeImmutable $lockedAt,
        public ?\DateTimeImmutable $evaluatedAt,
    ) {}

    public static function missing(): self
    {
        return new self(false, false, false, null, null, null, null);
    }

    public static function active(
        \DateTimeImmutable $lastUserActivityAt,
        ?\DateTimeImmutable $evaluatedAt = null,
    ): self
    {
        return new self(true, false, false, null, $lastUserActivityAt, null, $evaluatedAt);
    }

    public static function locked(
        \DateTimeImmutable $lastUserActivityAt,
        \DateTimeImmutable $lockedAt,
        string $reason,
        bool $transitioned,
        ?\DateTimeImmutable $evaluatedAt = null,
    ): self {
        return new self(true, true, $transitioned, $reason, $lastUserActivityAt, $lockedAt, $evaluatedAt);
    }

    public function idleExpiresAt(int $timeoutMinutes): ?\DateTimeImmutable
    {
        if (!$this->sessionExists || $this->locked || $timeoutMinutes < 1 || $this->lastUserActivityAt === null) {
            return null;
        }
        return $this->lastUserActivityAt->modify(sprintf('+%d minutes', $timeoutMinutes));
    }
}

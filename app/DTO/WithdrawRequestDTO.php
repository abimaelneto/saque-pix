<?php

declare(strict_types=1);

namespace App\DTO;

class WithdrawRequestDTO
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $method,
        public readonly string $pixType,
        public readonly string $pixKey,
        public readonly string $amount,
        public readonly ?string $schedule = null,
    ) {
    }

    public function isScheduled(): bool
    {
        return $this->schedule !== null;
    }

    public function getScheduledDateTime(): ?\DateTime
    {
        if (!$this->isScheduled()) {
            return null;
        }

        try {
            return new \DateTime($this->schedule);
        } catch (\Exception $e) {
            return null;
        }
    }
}


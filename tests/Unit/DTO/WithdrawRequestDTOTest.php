<?php

declare(strict_types=1);

namespace Tests\Unit\DTO;

use App\DTO\WithdrawRequestDTO;
use PHPUnit\Framework\TestCase;

class WithdrawRequestDTOTest extends TestCase
{
    public function testIsScheduledReturnsTrueWhenScheduleIsProvided(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: '2026-01-01 15:00',
        );

        $this->assertTrue($dto->isScheduled());
    }

    public function testIsScheduledReturnsFalseWhenScheduleIsNull(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: null,
        );

        $this->assertFalse($dto->isScheduled());
    }

    public function testGetScheduledDateTimeReturnsNullWhenNotScheduled(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: null,
        );

        $this->assertNull($dto->getScheduledDateTime());
    }

    public function testGetScheduledDateTimeReturnsDateTimeWhenScheduled(): void
    {
        $schedule = '2026-01-01 15:00';
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $schedule,
        );

        $dateTime = $dto->getScheduledDateTime();
        
        $this->assertInstanceOf(\DateTime::class, $dateTime);
        $this->assertEquals($schedule, $dateTime->format('Y-m-d H:i'));
    }
}


<?php

declare(strict_types=1);

namespace Tests\Unit\DTO;

use App\DTO\WithdrawRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Testes unitários para edge cases do WithdrawRequestDTO
 */
class WithdrawRequestDTOEdgeCasesTest extends TestCase
{
    public function testIsScheduledReturnsTrueForFutureDate(): void
    {
        $futureDate = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i');
        
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $futureDate,
        );

        $this->assertTrue($dto->isScheduled());
    }

    public function testIsScheduledReturnsFalseForNullSchedule(): void
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

    public function testIsScheduledReturnsFalseForEmptySchedule(): void
    {
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: '',
        );

        $this->assertFalse($dto->isScheduled());
    }

    public function testGetScheduledDateTimeReturnsNullForNullSchedule(): void
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

    public function testGetScheduledDateTimeReturnsDateTimeForValidSchedule(): void
    {
        $futureDate = (new \DateTime())->modify('+1 day')->format('Y-m-d H:i');
        
        $dto = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $futureDate,
        );

        $scheduledDateTime = $dto->getScheduledDateTime();
        $this->assertInstanceOf(\DateTime::class, $scheduledDateTime);
    }

    public function testGetScheduledDateTimeHandlesDifferentTimeFormats(): void
    {
        $date1 = '2026-12-25 10:00';
        $date2 = '2026-12-25 23:59';
        $date3 = '2026-01-01 00:00';

        $dto1 = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $date1,
        );

        $dto2 = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $date2,
        );

        $dto3 = new WithdrawRequestDTO(
            accountId: '123e4567-e89b-12d3-a456-426614174000',
            method: 'PIX',
            pixType: 'email',
            pixKey: 'test@email.com',
            amount: '100.00',
            schedule: $date3,
        );

        $this->assertInstanceOf(\DateTime::class, $dto1->getScheduledDateTime());
        $this->assertInstanceOf(\DateTime::class, $dto2->getScheduledDateTime());
        $this->assertInstanceOf(\DateTime::class, $dto3->getScheduledDateTime());
    }
}


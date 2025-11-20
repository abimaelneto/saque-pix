<?php

declare(strict_types=1);

namespace Tests\Unit\Factory;

use App\Factory\WithdrawMethodStrategyFactory;
use App\Strategy\PixWithdrawStrategy;
use App\Strategy\WithdrawMethodStrategy;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

class WithdrawMethodStrategyFactoryTest extends TestCase
{
    private ContainerInterface $container;
    private WithdrawMethodStrategyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->container = $this->createMock(ContainerInterface::class);
        $this->factory = new WithdrawMethodStrategyFactory($this->container);
    }

    public function testCreatePixStrategy(): void
    {
        $pixStrategy = $this->createMock(PixWithdrawStrategy::class);
        
        $this->container->expects($this->once())
            ->method('get')
            ->with(PixWithdrawStrategy::class)
            ->willReturn($pixStrategy);

        $strategy = $this->factory->create('PIX');
        
        $this->assertInstanceOf(WithdrawMethodStrategy::class, $strategy);
        $this->assertSame($pixStrategy, $strategy);
    }

    public function testCreatePixStrategyCaseInsensitive(): void
    {
        $pixStrategy = $this->createMock(PixWithdrawStrategy::class);
        
        $this->container->expects($this->once())
            ->method('get')
            ->with(PixWithdrawStrategy::class)
            ->willReturn($pixStrategy);

        $strategy = $this->factory->create('pix');
        
        $this->assertInstanceOf(WithdrawMethodStrategy::class, $strategy);
    }

    public function testCreateUnsupportedMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported withdraw method');
        
        $this->factory->create('TED');
    }

    public function testGetSupportedMethods(): void
    {
        $methods = $this->factory->getSupportedMethods();
        
        $this->assertIsArray($methods);
        $this->assertContains('PIX', $methods);
    }
}


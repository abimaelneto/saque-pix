<?php

declare(strict_types=1);

namespace App\Factory;

use App\Strategy\PixWithdrawStrategy;
use App\Strategy\WithdrawMethodStrategy;
use Psr\Container\ContainerInterface;

/**
 * Factory Pattern para criar strategies de métodos de saque
 * 
 * Facilita a extensão para novos métodos sem modificar código existente
 */
class WithdrawMethodStrategyFactory
{
    public function __construct(
        private ContainerInterface $container,
    ) {
    }

    /**
     * Cria a strategy apropriada baseada no método de saque
     * 
     * @param string $method Método de saque (ex: "PIX", "TED", "DOC")
     * @return WithdrawMethodStrategy
     * @throws \InvalidArgumentException Se o método não for suportado
     */
    public function create(string $method): WithdrawMethodStrategy
    {
        return match (strtoupper($method)) {
            'PIX' => $this->container->get(PixWithdrawStrategy::class),
            // Futuros métodos podem ser adicionados aqui:
            // 'TED' => $this->container->get(TedWithdrawStrategy::class),
            // 'DOC' => $this->container->get(DocWithdrawStrategy::class),
            default => throw new \InvalidArgumentException("Unsupported withdraw method: {$method}"),
        };
    }

    /**
     * Lista todos os métodos de saque suportados
     * 
     * @return array<string>
     */
    public function getSupportedMethods(): array
    {
        return ['PIX'];
    }
}


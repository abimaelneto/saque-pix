<?php

declare(strict_types=1);

/**
 * Dependency Injection Configuration
 * 
 * No Hyperf, classes concretas não precisam ser registradas aqui.
 * Apenas interfaces ou classes que precisam de binding específico.
 * 
 * O Hyperf usa auto-wiring baseado em type hints e annotations #[Inject]
 */
return [
    // Exemplo de como registrar uma interface:
    // \App\Contract\RepositoryInterface::class => \App\Repository\ConcreteRepository::class,
];


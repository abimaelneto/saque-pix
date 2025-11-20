<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Event\EventDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Service para disparar eventos de domínio
 * 
 * Wrapper para o EventDispatcher do Hyperf que facilita o uso
 */
class EventDispatcherService
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * Dispara um evento
     */
    public function dispatch(object $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }
}


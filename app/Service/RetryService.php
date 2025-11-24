<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Service para executar operações com retry logic e exponential backoff
 * 
 * Melhora resiliência do sistema em caso de falhas temporárias
 * Útil para integrações externas, emails, etc.
 */
class RetryService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Executa uma operação com retry e exponential backoff
     * 
     * @param callable $operation - Operação a executar
     * @param int $maxRetries - Número máximo de tentativas (padrão: 3)
     * @param int $initialDelayMs - Delay inicial em milissegundos (padrão: 1000ms)
     * @param array $retryableExceptions - Exceções que devem ser retentadas (vazio = todas)
     * @return mixed - Resultado da operação
     * @throws \Exception - Última exceção se todas tentativas falharem
     */
    public function executeWithRetry(
        callable $operation,
        int $maxRetries = 3,
        int $initialDelayMs = 1000,
        array $retryableExceptions = []
    ): mixed {
        $attempt = 0;
        $delay = $initialDelayMs;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                $result = $operation();
                
                // Se sucesso na primeira tentativa, não logar
                if ($attempt > 0) {
                    $this->logger->info('Operation succeeded after retry', [
                        'attempt' => $attempt + 1,
                        'max_retries' => $maxRetries,
                    ]);
                }
                
                return $result;
            } catch (\Exception $e) {
                $attempt++;
                $lastException = $e;

                // Verificar se exceção é retryable
                if (!empty($retryableExceptions)) {
                    $isRetryable = false;
                    foreach ($retryableExceptions as $retryableException) {
                        if ($e instanceof $retryableException) {
                            $isRetryable = true;
                            break;
                        }
                    }
                    
                    if (!$isRetryable) {
                        // Exceção não é retryable, lançar imediatamente
                        throw $e;
                    }
                }

                // Se atingiu máximo de tentativas, lançar exceção
                if ($attempt >= $maxRetries) {
                    $this->logger->error('Operation failed after all retries', [
                        'attempt' => $attempt,
                        'max_retries' => $maxRetries,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                    
                    throw $e;
                }

                // Logar tentativa de retry
                $this->logger->warning('Operation failed, retrying', [
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'delay_ms' => $delay,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);

                // Exponential backoff: delay dobra a cada tentativa
                usleep($delay * 1000); // Converter ms para microsegundos
                $delay *= 2;
            }
        }

        // Não deveria chegar aqui, mas por segurança
        if ($lastException) {
            throw $lastException;
        }

        throw new \RuntimeException('Operation failed without exception');
    }

    /**
     * Executa operação com retry apenas para exceções específicas
     * 
     * @param callable $operation
     * @param string[] $retryableExceptionClasses - Classes de exceção que devem ser retentadas
     * @param int $maxRetries
     * @param int $initialDelayMs
     * @return mixed
     */
    public function executeWithRetryFor(
        callable $operation,
        array $retryableExceptionClasses,
        int $maxRetries = 3,
        int $initialDelayMs = 1000
    ): mixed {
        return $this->executeWithRetry(
            $operation,
            $maxRetries,
            $initialDelayMs,
            $retryableExceptionClasses
        );
    }
}


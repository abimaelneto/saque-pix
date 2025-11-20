<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * Service para gerenciar distributed locks usando Redis
 * 
 * Essencial para escalabilidade horizontal e evitar processamento duplicado
 * em ambientes serverless ou com múltiplas instâncias
 */
class DistributedLockService
{
    private const LOCK_TTL = 60; // 60 segundos

    public function __construct(
        private Redis $redis,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Tenta adquirir um lock distribuído
     * 
     * @param string $key - Chave do lock
     * @param int $ttl - Tempo de vida do lock em segundos
     * @return string|null - Token do lock se adquirido, null caso contrário
     */
    public function acquireLock(string $key, int $ttl = self::LOCK_TTL): ?string
    {
        $token = uniqid('', true);
        $lockKey = "lock:{$key}";
        
        // SET com NX (only if not exists) e EX (expiration)
        $acquired = $this->redis->set($lockKey, $token, ['nx', 'ex' => $ttl]);
        
        if ($acquired) {
            $this->logger->info('Distributed lock acquired', [
                'key' => $key,
                'token' => $token,
                'ttl' => $ttl,
            ]);
            return $token;
        }
        
        $this->logger->debug('Failed to acquire distributed lock', [
            'key' => $key,
        ]);
        
        return null;
    }

    /**
     * Libera um lock distribuído
     * 
     * @param string $key - Chave do lock
     * @param string $token - Token do lock
     * @return bool - True se liberado com sucesso
     */
    public function releaseLock(string $key, string $token): bool
    {
        $lockKey = "lock:{$key}";
        
        // Lua script para garantir atomicidade (verificar token antes de deletar)
        $script = "
            if redis.call('get', KEYS[1]) == ARGV[1] then
                return redis.call('del', KEYS[1])
            else
                return 0
            end
        ";
        
        $released = $this->redis->eval($script, [$lockKey, $token], 1);
        
        if ($released) {
            $this->logger->info('Distributed lock released', [
                'key' => $key,
                'token' => $token,
            ]);
        }
        
        return (bool) $released;
    }

    /**
     * Executa uma função com lock distribuído
     * 
     * @param string $key - Chave do lock
     * @param callable $callback - Função a executar
     * @param int $ttl - Tempo de vida do lock
     * @return mixed - Retorno da função ou null se lock não foi adquirido
     */
    public function executeWithLock(string $key, callable $callback, int $ttl = self::LOCK_TTL): mixed
    {
        $token = $this->acquireLock($key, $ttl);
        
        if (!$token) {
            return null;
        }
        
        try {
            return $callback();
        } finally {
            $this->releaseLock($key, $token);
        }
    }

    /**
     * Verifica se um lock existe
     */
    public function isLocked(string $key): bool
    {
        $lockKey = "lock:{$key}";
        return $this->redis->exists($lockKey) > 0;
    }
}


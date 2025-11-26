<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Helper para mascarar dados sensíveis em logs
 */
class LogMasker
{
    /**
     * Campos que devem ser mascarados
     */
    private const SENSITIVE_FIELDS = [
        'pix_key',
        'key',
        'account_id',
        'user_id',
        'token',
        'password',
        'secret',
        'authorization',
        'idempotency_key',
    ];

    /**
     * Mascara dados sensíveis em um array
     */
    public static function mask(array $data): array
    {
        $masked = [];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            // Verificar se é campo sensível
            $isSensitive = false;
            foreach (self::SENSITIVE_FIELDS as $sensitiveField) {
                if (str_contains($lowerKey, $sensitiveField)) {
                    $isSensitive = true;
                    break;
                }
            }
            
            if ($isSensitive && is_string($value) && !empty($value)) {
                $masked[$key] = self::maskValue($value);
            } elseif (is_array($value)) {
                $masked[$key] = self::mask($value);
            } else {
                $masked[$key] = $value;
            }
        }
        
        return $masked;
    }

    /**
     * Mascara um valor individual
     */
    private static function maskValue(string $value): string
    {
        $length = strlen($value);
        
        if ($length <= 4) {
            // Valores muito curtos: mascarar tudo
            return str_repeat('*', $length);
        }
        
        if ($length <= 8) {
            // Valores médios: mostrar 2 primeiros, mascarar resto
            return substr($value, 0, 2) . str_repeat('*', $length - 2);
        }
        
        // Valores longos: mostrar 4 primeiros, mascarar resto
        return substr($value, 0, 4) . str_repeat('*', min($length - 4, 20));
    }
}


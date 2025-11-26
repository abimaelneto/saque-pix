<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Service de Criptografia
 * 
 * Usa OpenSSL AES-256-GCM para criptografar dados sensíveis
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12; // 96 bits para GCM
    private const TAG_LENGTH = 16; // 128 bits para GCM

    /**
     * Obtém chave de criptografia
     */
    private function getKey(): string
    {
        $key = env('ENCRYPTION_KEY');
        
        if (empty($key)) {
            if (env('APP_ENV') === 'production') {
                throw new \RuntimeException('ENCRYPTION_KEY must be configured in production');
            }
            // Em desenvolvimento, usar chave derivada do diretório
            // Em produção, deve ser uma chave forte de 32 bytes (256 bits)
            $key = hash('sha256', 'dev-encryption-key-' . __DIR__);
        }
        
        // Garantir que a chave tem 32 bytes (256 bits)
        if (strlen($key) < 32) {
            $key = hash('sha256', $key);
        }
        
        return substr($key, 0, 32);
    }

    /**
     * Criptografa um valor
     */
    public function encrypt(string $value): string
    {
        if (empty($value)) {
            return $value;
        }

        $key = $this->getKey();
        $iv = random_bytes(self::IV_LENGTH);
        
        $encrypted = openssl_encrypt(
            $value,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Combinar IV + tag + dados criptografados e codificar em base64
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Descriptografa um valor
     */
    public function decrypt(string $encryptedValue): string
    {
        if (empty($encryptedValue)) {
            return $encryptedValue;
        }

        // Verificar se está no formato antigo (não criptografado)
        // Isso permite migração gradual
        if (!$this->isEncrypted($encryptedValue)) {
            return $encryptedValue;
        }

        $key = $this->getKey();
        $data = base64_decode($encryptedValue, true);
        
        if ($data === false) {
            throw new \RuntimeException('Invalid encrypted data format');
        }

        if (strlen($data) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('Encrypted data too short');
        }

        $iv = substr($data, 0, self::IV_LENGTH);
        $tag = substr($data, self::IV_LENGTH, self::TAG_LENGTH);
        $encrypted = substr($data, self::IV_LENGTH + self::TAG_LENGTH);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * Verifica se um valor está criptografado
     */
    private function isEncrypted(string $value): bool
    {
        // Valores criptografados são base64 e têm tamanho mínimo
        if (strlen($value) < 20) {
            return false;
        }

        // Tentar decodificar base64
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        // Verificar se tem tamanho suficiente para IV + tag + dados
        return strlen($decoded) >= self::IV_LENGTH + self::TAG_LENGTH + 1;
    }
}


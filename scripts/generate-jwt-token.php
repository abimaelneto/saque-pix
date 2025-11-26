<?php

/**
 * Script auxiliar para gerar tokens JWT de teste
 * 
 * Uso:
 *   php scripts/generate-jwt-token.php [user_id] [account_id]
 * 
 * Exemplo:
 *   php scripts/generate-jwt-token.php user-123 account-456
 */

require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Obter argumentos
$userId = $argv[1] ?? 'test-user';
$accountId = $argv[2] ?? null;

// Obter secret (mesma lógica do AuthMiddleware)
$jwtSecret = getenv('JWT_SECRET');
if (empty($jwtSecret)) {
    if (getenv('APP_ENV') === 'production') {
        echo "❌ Erro: JWT_SECRET não configurado em produção\n";
        exit(1);
    }
    // Em desenvolvimento, usar secret padrão
    $jwtSecret = 'dev-secret-key-change-in-production-' . md5(__DIR__);
}

// Payload do token
$payload = [
    'user_id' => $userId,
    'account_id' => $accountId,
    'iat' => time(), // Issued at
    'exp' => time() + (60 * 60 * 24), // Expira em 24 horas
];

// Gerar token
$token = JWT::encode($payload, $jwtSecret, 'HS256');

echo "\n✅ Token JWT gerado com sucesso!\n\n";
echo "Token: {$token}\n\n";
echo "Use no header Authorization:\n";
echo "Authorization: Bearer {$token}\n\n";
echo "Payload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";


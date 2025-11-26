#!/usr/bin/env php
<?php

/**
 * Script de teste para saques imediatos
 * 
 * Cria saques imediatos e verifica se são processados automaticamente.
 */

declare(strict_types=1);

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/helper.php';

\Hyperf\Di\ClassLoader::init();
/** @var \Psr\Container\ContainerInterface $container */
$container = require BASE_PATH . '/config/container.php';

$accountRepo = $container->get(\App\Repository\AccountRepository::class);
$withdrawService = $container->get(\App\Service\WithdrawService::class);
$withdrawRepo = $container->get(\App\Repository\AccountWithdrawRepository::class);

echo "🧪 Teste de Saques Imediatos\n";
echo "============================\n\n";

// 1. Criar ou buscar conta de teste
echo "1️⃣ Criando conta de teste...\n";
$account = new \App\Model\Account();
$account->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
$account->name = 'Teste Saques Imediatos';
$account->balance = '5000.00';
$account->save();
echo "   ✅ Conta criada: {$account->id}\n";
echo "   💰 Saldo inicial: R$ " . number_format((float)$account->balance, 2, ',', '.') . "\n\n";

// 2. Criar saques imediatos
echo "2️⃣ Criando saques imediatos...\n\n";

$withdrawIds = [];
$amounts = [50.00, 100.00, 75.00];

foreach ($amounts as $index => $amount) {
    $dto = new \App\DTO\WithdrawRequestDTO(
        accountId: $account->id,
        method: 'PIX',
        pixType: 'email',
        pixKey: "test-immediate-{$index}@example.com",
        amount: (string)$amount,
        schedule: null // Imediato
    );
    
    try {
        echo "   Criando saque #{$index} (R$ " . number_format($amount, 2, ',', '.') . ")...\n";
        $withdraw = $withdrawService->createWithdraw($dto);
        $withdrawIds[] = $withdraw->id;
        
        // Verificar status imediatamente após criação
        $withdraw = $withdrawRepo->findById($withdraw->id);
        $status = $withdraw->done ? '✅ Processado' : ($withdraw->error ? '❌ Erro' : '⏳ Pendente');
        
        echo "      {$status} - ID: {$withdraw->id}\n";
        if ($withdraw->done) {
            echo "      ✅ Processado em: {$withdraw->processed_at?->format('Y-m-d H:i:s')}\n";
        } else if ($withdraw->error) {
            echo "      ❌ Erro: {$withdraw->error_reason}\n";
        } else {
            echo "      ⚠️  Pendente (não foi processado automaticamente!)\n";
        }
        echo "\n";
    } catch (\Exception $e) {
        echo "   ❌ Erro ao criar saque #{$index}: {$e->getMessage()}\n\n";
    }
}

// 3. Verificar saldo final
echo "3️⃣ Verificando saldo final da conta...\n";
$account = $accountRepo->findById($account->id);
echo "   💰 Saldo final: R$ " . number_format((float)$account->balance, 2, ',', '.') . "\n\n";

// 4. Verificar status de todos os saques
echo "4️⃣ Status final dos saques:\n";
$totalProcessed = 0;
$totalPending = 0;
$totalErrors = 0;

foreach ($withdrawIds as $withdrawId) {
    $withdraw = $withdrawRepo->findById($withdrawId);
    if ($withdraw) {
        if ($withdraw->done) {
            $totalProcessed++;
            echo "   ✅ Processado - ID: {$withdrawId}\n";
        } else if ($withdraw->error) {
            $totalErrors++;
            echo "   ❌ Erro - ID: {$withdrawId} - {$withdraw->error_reason}\n";
        } else {
            $totalPending++;
            echo "   ⚠️  Pendente - ID: {$withdrawId}\n";
            echo "      ⚠️  ATENÇÃO: Saque imediato não foi processado!\n";
        }
    }
}

echo "\n";
echo "📊 Resumo:\n";
echo "   ✅ Processados: {$totalProcessed}\n";
echo "   ⏳ Pendentes: {$totalPending}\n";
echo "   ❌ Erros: {$totalErrors}\n\n";

if ($totalPending > 0) {
    echo "⚠️  PROBLEMA DETECTADO: {$totalPending} saque(s) imediato(s) não foram processados!\n";
    echo "   Isso pode indicar um problema com:\n";
    echo "   - Lock distribuído (Redis)\n";
    echo "   - Processamento assíncrono\n";
    echo "   - Erro silencioso no processamento\n\n";
    echo "💡 Verifique os logs do servidor para mais detalhes.\n";
} else {
    echo "✅ Todos os saques imediatos foram processados corretamente!\n";
}

echo "\n";


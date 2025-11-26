#!/usr/bin/env php
<?php

/**
 * Script de teste para saques agendados
 * 
 * Cria saques agendados para o minuto seguinte e executa o cron job
 * para verificar se são processados corretamente.
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

echo "🧪 Teste de Saques Agendados\n";
echo "============================\n\n";

// 1. Criar ou buscar conta de teste
echo "1️⃣ Criando conta de teste...\n";
$account = new \App\Model\Account();
$account->id = \Ramsey\Uuid\Uuid::uuid4()->toString();
$account->name = 'Teste Saques Agendados';
$account->balance = '10000.00';
$account->save();
echo "   ✅ Conta criada: {$account->id}\n";
echo "   💰 Saldo: R$ " . number_format((float)$account->balance, 2, ',', '.') . "\n\n";

// 2. Calcular data para o minuto seguinte
$nextMinute = new \DateTime();
$nextMinute->modify('+1 minute');
$scheduleTime = $nextMinute->format('Y-m-d H:i');

echo "2️⃣ Criando saques agendados para: {$scheduleTime}\n";
echo "   (Aguarde 1 minuto e execute: make process-scheduled)\n\n";

$withdrawIds = [];
$amounts = [100.00, 200.00, 150.00];

foreach ($amounts as $index => $amount) {
    $dto = new \App\DTO\WithdrawRequestDTO(
        accountId: $account->id,
        method: 'PIX',
        pixType: 'email',
        pixKey: "test-scheduled-{$index}@example.com",
        amount: (string)$amount,
        schedule: $scheduleTime
    );
    
    try {
        $withdraw = $withdrawService->createWithdraw($dto);
        $withdrawIds[] = $withdraw->id;
        echo "   ✅ Saque #{$index} criado: {$withdraw->id}\n";
        echo "      💰 Valor: R$ " . number_format($amount, 2, ',', '.') . "\n";
        echo "      📧 PIX: test-scheduled-{$index}@example.com\n";
    } catch (\Exception $e) {
        echo "   ❌ Erro ao criar saque #{$index}: {$e->getMessage()}\n";
    }
}

echo "\n";
echo "3️⃣ Verificando saques criados...\n";
foreach ($withdrawIds as $withdrawId) {
    $withdraw = $withdrawRepo->findById($withdrawId);
    if ($withdraw) {
        $status = $withdraw->done ? '✅ Processado' : ($withdraw->error ? '❌ Erro' : '⏳ Pendente');
        echo "   {$status} - ID: {$withdrawId}\n";
        echo "      Agendado para: {$withdraw->scheduled_for?->format('Y-m-d H:i:s')}\n";
        echo "      Done: " . ($withdraw->done ? 'Sim' : 'Não') . "\n";
    }
}

echo "\n";
echo "📋 Próximos passos:\n";
echo "   1. Aguarde até {$scheduleTime}\n";
echo "   2. Execute: make process-scheduled\n";
echo "   3. Verifique os saques no admin: http://localhost:9501/admin\n";
echo "\n";
echo "💡 Ou atualize os saques para o passado e processe agora:\n";
echo "   curl -X POST http://localhost:9501/admin/api/update-scheduled-for-past\n";
echo "   make process-scheduled\n";
echo "\n";


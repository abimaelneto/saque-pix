#!/usr/bin/env php
<?php

/**
 * Stress Test Completo - Saque PIX API
 * 
 * Teste de stress realista com duração configurável
 * Gera carga variável para simular cenário real
 * Cria automaticamente múltiplas contas para distribuir carga (simula múltiplos usuários)
 * 
 * Uso: php scripts/stress-test-complete.php [account_id] [email] [duration] [num_accounts]
 * 
 * Parâmetros:
 *   account_id  - (opcional) ID da conta a usar. Se vazio/null, cria contas automaticamente
 *   email       - (opcional) Email para os saques PIX. Padrão: stress-test@example.com
 *   duration    - (opcional) Duração em segundos. Padrão: 60
 *   num_accounts - (opcional) Número de contas a criar se account_id não fornecido. Padrão: 10
 * 
 * Exemplos:
 *   # Criar 10 contas automaticamente (padrão)
 *   php scripts/stress-test-complete.php
 * 
 *   # Criar 20 contas
 *   php scripts/stress-test-complete.php "" "test@email.com" 60 20
 * 
 *   # Usar conta específica (não cria novas)
 *   php scripts/stress-test-complete.php "550e8400-..." "test@email.com" 60
 * 
 * Características:
 *   - Cria automaticamente múltiplas contas com saldo de 50 milhões cada
 *   - Distribui requisições aleatoriamente entre as contas (simula realidade)
 *   - Gera ondas de carga variável (500 → 1000 → 800 → 1200 → 600 req/s)
 *   - 80% saques imediatos, 20% agendados
 *   - Valores entre R$ 1.00 e R$ 50.99 por saque
 */

declare(strict_types=1);

// Configurações
$BASE_URL = getenv('BASE_URL') ?: 'http://localhost:9501';
$AUTH_TOKEN = getenv('AUTH_TOKEN') ?: 'Bearer test-token';
$DURATION = (int)($argv[3] ?? 60); // Segundos (padrão: 60s)
$MAX_CONCURRENT = 800; // Aumentado para permitir 1000+ req/s (era 500, agora 800)
$NUM_ACCOUNTS = (int)($argv[4] ?? 10); // Número de contas para distribuir carga (padrão: 10)

// Argumentos
$accountId = $argv[1] ?? null; // Se fornecido, usa apenas esta conta
$email = $argv[2] ?? 'stress-test@example.com';

// Cores
$GREEN = "\033[0;32m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$MAGENTA = "\033[0;35m";
$NC = "\033[0m";

echo "{$MAGENTA}🔥 Stress Test Completo - Saque PIX API{$NC}\n";
echo str_repeat("=", 60) . "\n\n";

// Verificar servidor
echo "{$YELLOW}🔍 Verificando servidor...{$NC}\n";
$maxRetries = 3;
$serverOk = false;

for ($i = 0; $i < $maxRetries; $i++) {
    $ch = curl_init("{$BASE_URL}/health");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $serverOk = true;
        break;
    }
    
    if ($i < $maxRetries - 1) {
        usleep(500000);
    }
}

if (!$serverOk) {
    echo "{$RED}❌ Servidor não está respondendo{$NC}\n";
    exit(1);
}
echo "{$GREEN}✅ Servidor está respondendo{$NC}\n\n";

// Função para criar conta
function createAccount($baseUrl, $accountNumber = null): ?string {
    $name = $accountNumber !== null 
        ? "Stress Test Account #{$accountNumber}"
        : 'Stress Test Account';
    
    $ch = curl_init("{$baseUrl}/accounts");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'name' => $name,
            'balance' => '50000000.00', // 50 milhões por conta (suficiente para ~500k saques de R$ 100)
        ]),
        CURLOPT_TIMEOUT => 5,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201 || $httpCode === 200) {
        $data = json_decode($response, true);
        return $data['data']['id'] ?? $data['id'] ?? null;
    }
    
    return null;
}

// Criar contas se necessário
$accountIds = [];

if (!empty($accountId)) {
    // Usar conta fornecida
    $accountIds = [$accountId];
    echo "{$GREEN}✅ Usando conta fornecida: {$accountId}{$NC}\n\n";
} else {
    // Criar múltiplas contas para distribuir carga (simula realidade)
    echo "{$YELLOW}📝 Criando {$NUM_ACCOUNTS} contas de teste para distribuir carga...{$NC}\n";
    
    for ($i = 1; $i <= $NUM_ACCOUNTS; $i++) {
        $createdId = createAccount($BASE_URL, $i);
        if ($createdId) {
            $accountIds[] = $createdId;
            if ($i <= 3 || $i === $NUM_ACCOUNTS) {
                echo "  {$GREEN}✅ Conta #{$i}: {$createdId}{$NC}\n";
            } elseif ($i === 4) {
                echo "  {$CYAN}... (criando mais contas){$NC}\n";
            }
        } else {
            echo "  {$RED}❌ Falha ao criar conta #{$i}{$NC}\n";
        }
    }
    
    if (empty($accountIds)) {
        echo "{$RED}❌ Falha ao criar contas{$NC}\n";
        exit(1);
    }
    
    echo "\n{$GREEN}✅ {$NUM_ACCOUNTS} contas criadas com sucesso!{$NC}\n";
    echo "{$YELLOW}💡 A carga será distribuída entre as contas para simular cenário real{$NC}\n\n";
}

// Função para criar handle (agora recebe accountId diretamente)
function createRequestHandle($url, $authToken, $accountId, $requestData) {
    $ch = curl_init("{$url}/account/{$accountId}/balance/withdraw");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: {$authToken}",
        ],
        CURLOPT_POSTFIELDS => $requestData,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    return $ch;
}

// Função para selecionar conta aleatória (distribui carga)
function getRandomAccountId(array $accountIds): string {
    return $accountIds[array_rand($accountIds)];
}

// Função para processar requisições concluídas
function processCompletedRequests($multiHandle, &$stats, &$activeHandles): int {
    $processed = 0;
    
    while ($info = curl_multi_info_read($multiHandle)) {
        if ($info['msg'] === CURLMSG_DONE) {
            $ch = $info['handle'];
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            $stats['total']++;
            $code = $httpCode;
            
            if (!isset($stats['http_codes'][$code])) {
                $stats['http_codes'][$code] = 0;
            }
            $stats['http_codes'][$code]++;
            
            // Contar como sucesso apenas 201 (Created) para saques
            // HTTP 200 pode ser de outros endpoints (health, etc)
            if ($httpCode === 201) {
                $stats['success']++;
            } elseif ($httpCode >= 200 && $httpCode < 300) {
                // Outros 2xx também são sucesso, mas não são criação de saque
                $stats['success']++;
            } else {
                $stats['errors']++;
            }
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
            
            $key = array_search($ch, $activeHandles, true);
            if ($key !== false) {
                unset($activeHandles[$key]);
            }
            
            $processed++;
        }
    }
    
    return $processed;
}

echo "{$BLUE}📊 Configuração do Stress Test{$NC}\n";
echo "   URL: {$BASE_URL}\n";
echo "   Contas: " . count($accountIds) . " conta(s)\n";
if (count($accountIds) <= 5) {
    foreach ($accountIds as $idx => $accId) {
        echo "     - {$accId}\n";
    }
} else {
    echo "     - {$accountIds[0]} (primeira)\n";
    echo "     - ... (mais " . (count($accountIds) - 2) . " contas)\n";
    echo "     - " . end($accountIds) . " (última)\n";
}
echo "   Email: {$email}\n";
echo "   Duração: {$DURATION} segundos\n";
echo "   Concorrência máxima: {$MAX_CONCURRENT}\n";
echo "   Distribuição: Carga será distribuída entre as contas\n\n";

echo "{$YELLOW}💡 Este teste simula carga variável (ondas de requisições){$NC}\n";
echo "{$YELLOW}💡 Abra o Grafana em http://localhost:3001 para ver métricas em tempo real{$NC}\n\n";

$stats = [
    'total' => 0,
    'success' => 0,
    'errors' => 0,
    'http_codes' => [],
];

$startTime = microtime(true);
$endTime = $startTime + $DURATION;
$lastStatsTime = $startTime;

$multiHandle = curl_multi_init();
$activeHandles = [];
$requestCount = 0;

// Simular ondas de carga (mais realista)
// Onda 1: 0-20% do tempo - 500 req/s
// Onda 2: 20-40% do tempo - 1000 req/s (pico)
// Onda 3: 40-60% do tempo - 800 req/s
// Onda 4: 60-80% do tempo - 1200 req/s (pico máximo)
// Onda 5: 80-100% do tempo - 600 req/s (decaimento)

$waves = [
    ['rps' => 500, 'start' => 0.0, 'end' => 0.2],
    ['rps' => 1000, 'start' => 0.2, 'end' => 0.4],
    ['rps' => 800, 'start' => 0.4, 'end' => 0.6],
    ['rps' => 1200, 'start' => 0.6, 'end' => 0.8],
    ['rps' => 600, 'start' => 0.8, 'end' => 1.0],
];

function getCurrentWaveRPS($waves, $elapsed, $duration): int {
    $progress = $elapsed / $duration;
    
    foreach ($waves as $wave) {
        if ($progress >= $wave['start'] && $progress < $wave['end']) {
            return $wave['rps'];
        }
    }
    
    return $waves[count($waves) - 1]['rps'];
}

echo "{$GREEN}🚀 Iniciando stress test...{$NC}\n\n";

$nextRequestTime = $startTime;
$currentWave = 0;

while (microtime(true) < $endTime) {
    $currentTime = microtime(true);
    $elapsed = $currentTime - $startTime;
    $remaining = $DURATION - $elapsed;
    
    if ($remaining <= 0) {
        break;
    }
    
    // Determinar RPS atual baseado na onda
    $targetRPS = getCurrentWaveRPS($waves, $elapsed, $DURATION);
    $intervalBetweenRequests = 1.0 / $targetRPS;
    
    // Adicionar requisições
    while ($currentTime >= $nextRequestTime && $currentTime < $endTime) {
        if (count($activeHandles) < $MAX_CONCURRENT) {
            // Alternar entre imediato e agendado (80% imediato, 20% agendado)
            $isScheduled = (rand(1, 100) > 80);
            
            if ($isScheduled) {
                $futureDate = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $schedule = "\"{$futureDate}\"";
            } else {
                $schedule = 'null';
            }
            
            // Valores menores para reduzir consumo de saldo e permitir mais requisições
            $amount = rand(1, 50) + (rand(0, 99) / 100); // R$ 1.00 a R$ 50.99
            
            // Selecionar conta aleatória para distribuir carga
            $selectedAccountId = getRandomAccountId($accountIds);
            
            $requestData = json_encode([
                'method' => 'PIX',
                'pix' => [
                    'type' => 'email',
                    'key' => $email,
                ],
                'amount' => $amount,
                'schedule' => $isScheduled ? $futureDate : null,
            ]);
            
            $ch = createRequestHandle($BASE_URL, $AUTH_TOKEN, $selectedAccountId, $requestData);
            curl_multi_add_handle($multiHandle, $ch);
            $activeHandles[] = $ch;
            $requestCount++;
        }
        
        $nextRequestTime += $intervalBetweenRequests;
        
        if ($nextRequestTime < $currentTime) {
            $nextRequestTime = $currentTime + $intervalBetweenRequests;
        }
    }
    
    // Processar requisições
    $stillRunning = 0;
    curl_multi_exec($multiHandle, $stillRunning);
    $completed = processCompletedRequests($multiHandle, $stats, $activeHandles);
    
    if ($completed > 0) {
        $activeHandles = array_values($activeHandles);
    }
    
    // Mostrar estatísticas a cada 5 segundos
    if ($currentTime - $lastStatsTime >= 5.0) {
        $rps = $stats['total'] / $elapsed;
        $successRate = $stats['total'] > 0 ? ($stats['success'] / $stats['total']) * 100 : 0;
        $active = count($activeHandles);
        $waveRPS = getCurrentWaveRPS($waves, $elapsed, $DURATION);
        
        echo sprintf(
            "{$CYAN}[%.0fs/%.0fs]{$NC} Total: {$GREEN}%d{$NC} | RPS: {$YELLOW}%.1f{$NC} (Target: {$BLUE}%d{$NC}) | Sucesso: {$GREEN}%.1f%%{$NC} | Erros: {$RED}%d{$NC} | Ativas: {$BLUE}%d{$NC}\n",
            $elapsed,
            $DURATION,
            $stats['total'],
            $rps,
            $waveRPS,
            $successRate,
            $stats['errors'],
            $active
        );
        
        $lastStatsTime = $currentTime;
    }
    
    if (count($activeHandles) === 0) {
        usleep(100);
    } else {
        curl_multi_select($multiHandle, 0.001);
    }
}

// Aguardar requisições pendentes
echo "\n{$YELLOW}⏳ Aguardando requisições pendentes...{$NC}\n";
$waitStart = microtime(true);
$maxWait = 30;

while (count($activeHandles) > 0 && (microtime(true) - $waitStart) < $maxWait) {
    $stillRunning = 0;
    curl_multi_exec($multiHandle, $stillRunning);
    processCompletedRequests($multiHandle, $stats, $activeHandles);
    $activeHandles = array_values($activeHandles);
    
    if (count($activeHandles) > 0) {
        curl_multi_select($multiHandle, 0.1);
    }
}

foreach ($activeHandles as $ch) {
    curl_multi_remove_handle($multiHandle, $ch);
    curl_close($ch);
}
curl_multi_close($multiHandle);

// Estatísticas finais
$totalTime = microtime(true) - $startTime;
$avgRps = $stats['total'] > 0 ? ($stats['total'] / $totalTime) : 0;
$successRate = $stats['total'] > 0 ? ($stats['success'] / $stats['total']) * 100 : 0;

echo "\n";
echo str_repeat("=", 60) . "\n";
echo "{$BLUE}📊 Estatísticas Finais{$NC}\n";
echo str_repeat("=", 60) . "\n";
echo "Tempo total: {$GREEN}" . number_format($totalTime, 2) . "s{$NC}\n";
echo "Requisições iniciadas: {$CYAN}{$requestCount}{$NC}\n";
echo "Requisições completadas: {$GREEN}{$stats['total']}{$NC}\n";
echo "RPS médio: {$YELLOW}" . number_format($avgRps, 2) . " req/s{$NC}\n";
echo "Taxa de sucesso: {$GREEN}" . number_format($successRate, 2) . "%{$NC}\n";
echo "Sucessos: {$GREEN}{$stats['success']}{$NC}\n";
echo "Erros: {$RED}{$stats['errors']}{$NC}\n";

if (!empty($stats['http_codes'])) {
    echo "\n{$CYAN}Códigos HTTP:{$NC}\n";
    ksort($stats['http_codes']);
    foreach ($stats['http_codes'] as $code => $count) {
        $color = ($code >= 200 && $code < 300) ? $GREEN : (($code >= 400) ? $RED : $YELLOW);
        echo "  {$color}HTTP {$code}:{$NC} {$count}\n";
    }
}

echo "\n";
echo "{$GREEN}✅ Stress test concluído!{$NC}\n";
echo "{$YELLOW}💡 Verifique o Grafana em http://localhost:3001 para análise detalhada{$NC}\n";
echo "\n";

exit(0);


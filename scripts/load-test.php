#!/usr/bin/env php
<?php

/**
 * Load Test - Saque PIX API
 * 
 * Gera 1000 requisições por segundo durante 5 segundos
 * para testar o comportamento do servidor sob carga.
 * 
 * Uso: php scripts/load-test.php [account_id] [email]
 */

declare(strict_types=1);

// Configurações
$BASE_URL = getenv('BASE_URL') ?: 'http://localhost:9501';
$AUTH_TOKEN = getenv('AUTH_TOKEN') ?: 'Bearer test-token';
$TARGET_RPS = 1000; // Requisições por segundo
$DURATION = 5; // Segundos
$MAX_CONCURRENT = 200; // Máximo de requisições concorrentes

// Argumentos
$accountId = $argv[1] ?? null;
$email = $argv[2] ?? 'load-test@example.com';

// Cores para output
$GREEN = "\033[0;32m";
$YELLOW = "\033[1;33m";
$BLUE = "\033[0;34m";
$RED = "\033[0;31m";
$CYAN = "\033[0;36m";
$NC = "\033[0m"; // No Color

echo "{$BLUE}🔥 Load Test - Saque PIX API{$NC}\n";
echo str_repeat("=", 50) . "\n\n";

// Verificar se servidor está rodando
echo "{$YELLOW}🔍 Verificando servidor...{$NC}\n";
$ch = curl_init("{$BASE_URL}/health");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_CONNECTTIMEOUT => 2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "{$RED}❌ Servidor não está respondendo em {$BASE_URL}{$NC}\n";
    echo "   Inicie o servidor com: make start-bg\n";
    exit(1);
}
echo "{$GREEN}✅ Servidor está respondendo{$NC}\n\n";

// Criar conta se necessário
if (empty($accountId)) {
    echo "{$YELLOW}📝 Criando conta de teste...{$NC}\n";
    
    $ch = curl_init("{$BASE_URL}/accounts");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'name' => 'Load Test Account',
            'balance' => '1000000.00', // Saldo alto para suportar muitas requisições
        ]),
        CURLOPT_TIMEOUT => 5,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 201) {
        $data = json_decode($response, true);
        $accountId = $data['data']['id'] ?? null;
        
        if ($accountId) {
            echo "{$GREEN}✅ Conta criada: {$accountId}{$NC}\n\n";
        } else {
            echo "{$RED}❌ Falha ao obter ID da conta{$NC}\n";
            exit(1);
        }
    } else {
        echo "{$RED}❌ Falha ao criar conta (HTTP {$httpCode}){$NC}\n";
        echo "   Resposta: {$response}\n";
        exit(1);
    }
} else {
    echo "{$CYAN}📋 Usando conta existente: {$accountId}{$NC}\n\n";
}

// Preparar dados da requisição
$requestData = json_encode([
    'method' => 'PIX',
    'pix' => [
        'type' => 'email',
        'key' => $email,
    ],
    'amount' => 10.00, // Valor fixo pequeno para não esgotar saldo rapidamente
    'schedule' => null, // Sempre imediato para simplificar
]);

// Estatísticas
$stats = [
    'total' => 0,
    'success' => 0,
    'errors' => 0,
    'http_codes' => [],
    'start_time' => 0,
];

echo "{$BLUE}📊 Iniciando Load Test{$NC}\n";
echo "   URL: {$BASE_URL}\n";
echo "   Conta: {$accountId}\n";
echo "   Email: {$email}\n";
echo "   Target: {$TARGET_RPS} req/s\n";
echo "   Duração: {$DURATION} segundos\n";
echo "   Concorrência máxima: {$MAX_CONCURRENT}\n\n";

echo "{$YELLOW}💡 Dica: Abra o Grafana em http://localhost:3001 para ver métricas em tempo real{$NC}\n\n";

// Função para criar um handle de requisição
function createRequestHandle($url, $authToken, $accountId, $requestData)
{
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

// Função para processar requisições concluídas
function processCompletedRequests($multiHandle, &$stats, &$activeHandles): int
{
    $processed = 0;
    
    while ($info = curl_multi_info_read($multiHandle)) {
        if ($info['msg'] === CURLMSG_DONE) {
            $ch = $info['handle'];
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            // Atualizar estatísticas
            $stats['total']++;
            $code = $httpCode;
            
            if (!isset($stats['http_codes'][$code])) {
                $stats['http_codes'][$code] = 0;
            }
            $stats['http_codes'][$code]++;
            
            if ($httpCode === 201 || $httpCode === 200) {
                $stats['success']++;
            } else {
                $stats['errors']++;
            }
            
            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
            
            // Remover do array de handles ativos
            $key = array_search($ch, $activeHandles, true);
            if ($key !== false) {
                unset($activeHandles[$key]);
            }
            
            $processed++;
        }
    }
    
    return $processed;
}

echo "{$GREEN}🚀 Iniciando...{$NC}\n\n";

$startTime = microtime(true);
$endTime = $startTime + $DURATION;
$lastStatsTime = $startTime;
$stats['start_time'] = $startTime;

// Pool de requisições ativas
$multiHandle = curl_multi_init();
$activeHandles = []; // Array para rastrear handles ativos
$requestCount = 0; // Contador total de requisições iniciadas
$requestsPerSecond = $TARGET_RPS;
$intervalBetweenRequests = 1.0 / $requestsPerSecond; // Tempo entre cada requisição (em segundos)
$nextRequestTime = $startTime;

// Loop principal - gerar requisições continuamente
while (true) {
    $currentTime = microtime(true);
    $elapsed = $currentTime - $startTime;
    
    // Verificar se devemos parar
    if ($currentTime >= $endTime) {
        break;
    }
    
    // Adicionar novas requisições para manter a taxa
    // Calculamos quantas requisições deveríamos ter iniciado até agora
    $targetRequestsStarted = (int)($requestsPerSecond * $elapsed);
    $requestsToAdd = $targetRequestsStarted - $requestCount;
    
    // Adicionar requisições respeitando o limite de concorrência
    while ($requestsToAdd > 0 && count($activeHandles) < $MAX_CONCURRENT && $currentTime < $endTime) {
        $ch = createRequestHandle($BASE_URL, $AUTH_TOKEN, $accountId, $requestData);
        curl_multi_add_handle($multiHandle, $ch);
        $activeHandles[] = $ch;
        $requestCount++;
        $requestsToAdd--;
    }
    
    // Executar requisições pendentes
    $stillRunning = 0;
    curl_multi_exec($multiHandle, $stillRunning);
    
    // Processar requisições concluídas (a função já remove do array)
    $completed = processCompletedRequests($multiHandle, $stats, $activeHandles);
    
    // Reindexar array após remoções
    if ($completed > 0) {
        $activeHandles = array_values($activeHandles);
    }
    
    // Mostrar estatísticas a cada segundo
    if ($currentTime - $lastStatsTime >= 1.0) {
        $rps = $stats['total'] / $elapsed;
        $successRate = $stats['total'] > 0 ? ($stats['success'] / $stats['total']) * 100 : 0;
        $active = count($activeHandles);
        $pending = $requestCount - $stats['total'];
        
        echo sprintf(
            "{$CYAN}[%.1fs]{$NC} Total: {$GREEN}%d{$NC} | RPS: {$YELLOW}%.1f{$NC} | Sucesso: {$GREEN}%.1f%%{$NC} | Erros: {$RED}%d{$NC} | Ativas: {$BLUE}%d{$NC} | Pendentes: {$YELLOW}%d{$NC}\n",
            $elapsed,
            $stats['total'],
            $rps,
            $successRate,
            $stats['errors'],
            $active,
            $pending
        );
        
        $lastStatsTime = $currentTime;
    }
    
    // Pequena pausa para não sobrecarregar CPU (apenas se não houver requisições ativas)
    if (count($activeHandles) === 0) {
        usleep(100); // 0.1ms
    } else {
        // Se há requisições ativas, usar select para esperar I/O
        curl_multi_select($multiHandle, 0.001);
    }
}

// Aguardar todas as requisições pendentes finalizarem
echo "\n{$YELLOW}⏳ Aguardando requisições pendentes finalizarem...{$NC}\n";
$maxWaitTime = 30; // Máximo de 30 segundos para esperar
$waitStart = microtime(true);

while (count($activeHandles) > 0 && (microtime(true) - $waitStart) < $maxWaitTime) {
    $stillRunning = 0;
    curl_multi_exec($multiHandle, $stillRunning);
    processCompletedRequests($multiHandle, $stats, $activeHandles);
    
    // Reindexar array após remoções
    $activeHandles = array_values($activeHandles);
    
    if (count($activeHandles) > 0) {
        curl_multi_select($multiHandle, 0.1);
    }
}

// Limpar handles restantes (se houver timeout)
foreach ($activeHandles as $ch) {
    curl_multi_remove_handle($multiHandle, $ch);
    curl_close($ch);
}
curl_multi_close($multiHandle);

// Estatísticas finais
$totalTime = microtime(true) - $startTime;
$avgRps = $stats['total'] > 0 ? ($stats['total'] / $totalTime) : 0;
$successRate = $stats['total'] > 0 ? ($stats['success'] / $stats['total']) * 100 : 0;
$targetTotal = $TARGET_RPS * $DURATION;
$coverage = $stats['total'] > 0 ? ($stats['total'] / $targetTotal) * 100 : 0;

echo "\n";
echo str_repeat("=", 50) . "\n";
echo "{$BLUE}📊 Estatísticas Finais{$NC}\n";
echo str_repeat("=", 50) . "\n";
echo "Tempo total: {$GREEN}" . number_format($totalTime, 2) . "s{$NC}\n";
echo "Requisições iniciadas: {$CYAN}{$requestCount}{$NC}\n";
echo "Requisições completadas: {$GREEN}{$stats['total']}{$NC}\n";
echo "Target esperado: {$YELLOW}{$targetTotal}{$NC}\n";
echo "Cobertura: {$CYAN}" . number_format($coverage, 1) . "%{$NC}\n";
echo "Requisições por segundo (média): {$YELLOW}" . number_format($avgRps, 2) . " req/s{$NC}\n";
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
echo "{$GREEN}✅ Load test concluído!{$NC}\n";
echo "{$YELLOW}💡 Verifique o Grafana em http://localhost:3001 para análise detalhada{$NC}\n";
echo "\n";

exit(0);

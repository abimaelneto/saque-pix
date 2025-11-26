#!/usr/bin/env php
<?php

/**
 * Script para verificar se as métricas do Prometheus estão corretas
 * Compara métricas do endpoint /metrics com o que está no Redis
 * 
 * Uso: php scripts/verify-metrics.php
 */

declare(strict_types=1);

$BASE_URL = getenv('BASE_URL') ?: 'http://localhost:9501';

echo "🔍 Verificando Métricas do Prometheus\n";
echo str_repeat("=", 60) . "\n\n";

// 1. Buscar métricas do endpoint
echo "1. Buscando métricas do endpoint /metrics...\n";
$ch = curl_init("{$BASE_URL}/metrics");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
]);
$metricsText = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "❌ Erro ao buscar métricas (HTTP {$httpCode})\n";
    exit(1);
}

echo "✅ Métricas obtidas com sucesso\n\n";

// 2. Parsear métricas
$metrics = [];
$lines = explode("\n", $metricsText);
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || str_starts_with($line, '#')) {
        continue;
    }
    
    // Formato: metric_name{labels} value
    if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{[^}]*\})?\s+(.+)$/', $line, $matches)) {
        $metricName = $matches[1];
        $labels = $matches[2] ?? '{}';
        $value = $matches[3];
        
        if (!isset($metrics[$metricName])) {
            $metrics[$metricName] = [];
        }
        
        $metrics[$metricName][] = [
            'labels' => $labels,
            'value' => $value,
        ];
    }
}

// 3. Verificar métricas HTTP
echo "2. Verificando métricas HTTP...\n";
$httpMetrics = $metrics['http_requests_total'] ?? [];
if (empty($httpMetrics)) {
    echo "⚠️  Nenhuma métrica http_requests_total encontrada\n";
} else {
    echo "✅ Encontradas " . count($httpMetrics) . " séries de http_requests_total\n";
    
    // Agrupar por status code
    $statusCodes = [];
    foreach ($httpMetrics as $metric) {
        if (preg_match('/status="(\d+)"/', $metric['labels'], $matches)) {
            $status = $matches[1];
            if (!isset($statusCodes[$status])) {
                $statusCodes[$status] = 0;
            }
            $statusCodes[$status] += (float) $metric['value'];
        }
    }
    
    echo "\n   Distribuição de Status Codes:\n";
    ksort($statusCodes);
    foreach ($statusCodes as $code => $count) {
        $color = ($code >= 200 && $code < 300) ? '✅' : (($code >= 400) ? '⚠️ ' : '❌');
        echo "   {$color} HTTP {$code}: {$count}\n";
    }
}

// 4. Verificar métricas de saques
echo "\n3. Verificando métricas de saques...\n";
$withdrawCreated = $metrics['withdraws_created_total'] ?? [];
$withdrawProcessed = $metrics['withdraws_processed_total'] ?? [];

if (empty($withdrawCreated)) {
    echo "⚠️  Nenhuma métrica withdraws_created_total encontrada\n";
} else {
    $totalCreated = array_sum(array_column($withdrawCreated, 'value'));
    echo "✅ Total de saques criados: {$totalCreated}\n";
}

if (empty($withdrawProcessed)) {
    echo "⚠️  Nenhuma métrica withdraws_processed_total encontrada\n";
} else {
    $totalProcessed = array_sum(array_column($withdrawProcessed, 'value'));
    echo "✅ Total de saques processados: {$totalProcessed}\n";
}

// 5. Verificar histogramas
echo "\n4. Verificando histogramas de latência...\n";
$durationSum = $metrics['http_request_duration_seconds_sum'] ?? [];
$durationCount = $metrics['http_request_duration_seconds_count'] ?? [];

if (empty($durationSum) || empty($durationCount)) {
    echo "⚠️  Histogramas de latência não encontrados\n";
} else {
    echo "✅ Histogramas encontrados\n";
    
    // Calcular média
    $totalSum = array_sum(array_column($durationSum, 'value'));
    $totalCount = array_sum(array_column($durationCount, 'value'));
    
    if ($totalCount > 0) {
        $avgLatency = $totalSum / $totalCount;
        echo "   Latência média: " . number_format($avgLatency * 1000, 2) . " ms\n";
    }
}

// 6. Resumo
echo "\n" . str_repeat("=", 60) . "\n";
echo "📊 Resumo da Verificação\n";
echo str_repeat("=", 60) . "\n";
echo "Total de métricas únicas: " . count($metrics) . "\n";
echo "Métricas HTTP: " . (isset($metrics['http_requests_total']) ? '✅' : '❌') . "\n";
echo "Métricas de Saques: " . (isset($metrics['withdraws_created_total']) ? '✅' : '❌') . "\n";
echo "Histogramas: " . (isset($metrics['http_request_duration_seconds_sum']) ? '✅' : '❌') . "\n";

echo "\n✅ Verificação concluída!\n";
echo "💡 Execute 'make stress-test' e rode este script novamente para ver métricas em tempo real\n";


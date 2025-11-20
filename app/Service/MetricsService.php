<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Redis\Redis;
use Psr\Log\LoggerInterface;

/**
 * Service para coletar métricas de negócio
 * 
 * Armazena métricas em Redis para fácil acesso e agregação
 * Compatível com Prometheus, CloudWatch, etc.
 */
class MetricsService
{
    private const METRICS_PREFIX = 'metrics:';
    private const METRICS_LABEL_PREFIX = 'metrics_labels:';
    private const METRICS_TTL = 86400 * 7; // 7 dias

    public function __construct(
        private Redis $redis,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Incrementa contador de métrica
     */
    public function incrementCounter(string $metric, array $labels = []): void
    {
        $key = $this->buildMetricKey($metric, $labels);
        $this->redis->incr($key);
        $this->redis->expire($key, self::METRICS_TTL);
        
        $this->logger->info('Metric incremented', [
            'metric' => $metric,
            'labels' => $labels,
            'key' => $key,
        ]);
    }

    /**
     * Registra valor de histograma (para métricas de tempo, tamanho, etc)
     */
    public function recordHistogram(string $metric, float $value, array $labels = []): void
    {
        $key = $this->buildMetricKey($metric, $labels);
        $this->redis->lpush($key . ':values', (string) $value);
        $this->redis->ltrim($key . ':values', 0, 999); // Manter últimos 1000 valores
        $this->redis->expire($key . ':values', self::METRICS_TTL);
        
        // Atualizar estatísticas agregadas
        $this->updateHistogramStats($key, $value);
        
        $this->logger->debug('Histogram recorded', [
            'metric' => $metric,
            'value' => $value,
            'labels' => $labels,
        ]);
    }

    /**
     * Registra valor de gauge (valor atual, não acumulativo)
     */
    public function setGauge(string $metric, float $value, array $labels = []): void
    {
        $key = $this->buildMetricKey($metric, $labels);
        $this->redis->set($key, (string) $value);
        $this->redis->expire($key, self::METRICS_TTL);
        
        $this->logger->debug('Gauge set', [
            'metric' => $metric,
            'value' => $value,
            'labels' => $labels,
        ]);
    }

    /**
     * Obtém valor de contador
     */
    public function getCounter(string $metric, array $labels = []): int
    {
        $key = $this->buildMetricKey($metric, $labels);
        return (int) ($this->redis->get($key) ?? 0);
    }

    /**
     * Obtém estatísticas de histograma
     */
    public function getHistogramStats(string $metric, array $labels = []): array
    {
        $key = $this->buildMetricKey($metric, $labels);
        
        return [
            'count' => (int) ($this->redis->get($key . ':count') ?? 0),
            'sum' => (float) ($this->redis->get($key . ':sum') ?? 0),
            'min' => (float) ($this->redis->get($key . ':min') ?? 0),
            'max' => (float) ($this->redis->get($key . ':max') ?? 0),
            'avg' => $this->calculateAverage($key),
        ];
    }

    /**
     * Obtém todas as métricas (para endpoint de métricas)
     */
    public function getAllMetrics(): array
    {
        $keys = $this->redis->keys(self::METRICS_PREFIX . '*');
        $metrics = [];
        
        foreach ($keys as $key) {
            if ($this->shouldSkipKey($key)) {
                continue;
            }
            
            $metricName = $this->extractMetricName($key);
            $labels = $this->extractLabels($key);
            
            if (!isset($metrics[$metricName])) {
                $metrics[$metricName] = [];
            }
            
            $value = $this->redis->get($key);
            $metrics[$metricName][] = [
                'labels' => $labels,
                'value' => $value !== null ? (float) $value : null,
            ];
        }
        
        return $metrics;
    }

    /**
     * Métricas de negócio específicas
     */
    
    public function recordWithdrawCreated(bool $scheduled, string $pixType): void
    {
        $this->incrementCounter('withdraws_created_total', [
            'type' => $scheduled ? 'scheduled' : 'immediate',
            'pix_type' => $pixType,
        ]);
    }

    public function recordWithdrawProcessed(bool $success, ?string $errorReason = null): void
    {
        $labels = ['status' => $success ? 'success' : 'error'];
        if ($errorReason) {
            $labels['error_type'] = $this->normalizeErrorType($errorReason);
        }
        
        $this->incrementCounter('withdraws_processed_total', $labels);
    }

    public function recordWithdrawAmount(float $amount, bool $scheduled): void
    {
        $this->recordHistogram('withdraw_amount', $amount, [
            'type' => $scheduled ? 'scheduled' : 'immediate',
        ]);
        
        // Incrementar valor total sacado (usando incrementByFloat para acumular valores)
        $key = $this->buildMetricKey('withdraw_amount_total', [
            'type' => $scheduled ? 'scheduled' : 'immediate',
        ]);
        $this->redis->incrByFloat($key, $amount);
        $this->redis->expire($key, self::METRICS_TTL);
    }

    public function recordWithdrawProcessingTime(float $seconds, bool $scheduled): void
    {
        $this->recordHistogram('withdraw_processing_time_seconds', $seconds, [
            'type' => $scheduled ? 'scheduled' : 'immediate',
        ]);
    }

    public function recordInsufficientBalance(string $context = 'withdraw'): void
    {
        $this->incrementCounter('insufficient_balance_total', [
            'context' => $context,
        ]);
    }

    public function recordEmailSent(bool $success): void
    {
        $this->incrementCounter('emails_sent_total', [
            'status' => $success ? 'success' : 'error',
        ]);
    }

    public function recordScheduledWithdrawsProcessed(int $count, int $successCount, int $errorCount): void
    {
        $this->incrementCounter('scheduled_withdraws_batch_processed_total', [
            'status' => 'total',
        ]);
        $this->incrementCounter('scheduled_withdraws_batch_processed_total', [
            'status' => 'success',
        ]);
        $this->incrementCounter('scheduled_withdraws_batch_processed_total', [
            'status' => 'error',
        ]);
    }

    public function recordHttpRequest(string $method, string $path, int $statusCode, float $duration): void
    {
        $this->incrementCounter('http_requests_total', [
            'method' => $method,
            'path' => $this->normalizePath($path),
            'status' => (string) $statusCode,
        ]);
        
        $this->recordHistogram('http_request_duration_seconds', $duration, [
            'method' => $method,
            'path' => $this->normalizePath($path),
            'status' => (string) $statusCode,
        ]);
    }

    /**
     * Métodos privados auxiliares
     */
    
    private function buildMetricKey(string $metric, array $labels): string
    {
        $key = self::METRICS_PREFIX . $metric;
        
        if (!empty($labels)) {
            ksort($labels);
            $labelPayload = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($labelPayload === false) {
                $labelPayload = serialize($labels);
            }

            $labelHash = md5($labelPayload);
            $this->storeLabelsMetadata($metric, $labelHash, $labels);
            $key .= ':' . $labelHash;
        }
        
        return $key;
    }

    private function extractMetricName(string $key): string
    {
        $key = str_replace(self::METRICS_PREFIX, '', $key);
        $parts = explode(':', $key);
        return $parts[0];
    }

    private function extractLabels(string $key): array
    {
        $key = str_replace(self::METRICS_PREFIX, '', $key);
        $parts = explode(':', $key);

        if (count($parts) < 2) {
            return [];
        }

        $metric = array_shift($parts);
        $hash = $parts[0];

        $labelKey = self::METRICS_LABEL_PREFIX . $metric . ':' . $hash;
        $labelsJson = $this->redis->get($labelKey);

        if ($labelsJson === null) {
            return [];
        }

        $decoded = json_decode($labelsJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function updateHistogramStats(string $key, float $value): void
    {
        $countKey = $key . ':count';
        $sumKey = $key . ':sum';
        $minKey = $key . ':min';
        $maxKey = $key . ':max';
        
        $this->redis->incr($countKey);
        $this->redis->incrByFloat($sumKey, $value);
        
        $currentMin = $this->redis->get($minKey);
        if ($currentMin === null || $value < (float) $currentMin) {
            $this->redis->set($minKey, (string) $value);
        }
        
        $currentMax = $this->redis->get($maxKey);
        if ($currentMax === null || $value > (float) $currentMax) {
            $this->redis->set($maxKey, (string) $value);
        }
        
        foreach ([$countKey, $sumKey, $minKey, $maxKey] as $statKey) {
            $this->redis->expire($statKey, self::METRICS_TTL);
        }
    }

    private function calculateAverage(string $key): float
    {
        $count = (int) ($this->redis->get($key . ':count') ?? 0);
        $sum = (float) ($this->redis->get($key . ':sum') ?? 0);
        
        return $count > 0 ? $sum / $count : 0.0;
    }

    private function normalizeErrorType(string $errorReason): string
    {
        $errorReason = strtolower($errorReason);
        
        if (str_contains($errorReason, 'insufficient') || str_contains($errorReason, 'balance')) {
            return 'insufficient_balance';
        }
        
        if (str_contains($errorReason, 'not found')) {
            return 'not_found';
        }
        
        if (str_contains($errorReason, 'validation') || str_contains($errorReason, 'invalid')) {
            return 'validation_error';
        }
        
        return 'unknown_error';
    }

    private function normalizePath(string $path): string
    {
        // Normalizar paths com IDs para métricas consistentes
        $path = preg_replace('/\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/{id}', $path);
        $path = preg_replace('/\/\d+/', '/{id}', $path);
        return $path;
    }
    private function storeLabelsMetadata(string $metric, string $hash, array $labels): void
    {
        $labelKey = self::METRICS_LABEL_PREFIX . $metric . ':' . $hash;
        $payload = json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = serialize($labels);
        }

        $this->redis->set($labelKey, $payload);
        $this->redis->expire($labelKey, self::METRICS_TTL);
    }

    private function shouldSkipKey(string $key): bool
    {
        foreach ([':values', ':count', ':sum', ':min', ':max'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }
}


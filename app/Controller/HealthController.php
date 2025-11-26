<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MetricsService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

// Rotas definidas em config/routes.php
class HealthController
{
    public function __construct(
        private MetricsService $metricsService,
        private Redis $redis,
        private ResponseInterface $response,
    ) {
    }

    public function health(): PsrResponseInterface
    {
        $checks = [
            'status' => 'ok',
            'timestamp' => date('c'),
            'checks' => [],
        ];

        // Verificar banco de dados
        try {
            Db::select('SELECT 1');
            $checks['checks']['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['status'] = 'degraded';
            $checks['checks']['database'] = 'error: ' . $e->getMessage();
        }

        // Verificar Redis
        try {
            $this->redis->ping();
            $checks['checks']['redis'] = 'ok';
        } catch (\Exception $e) {
            $checks['status'] = 'degraded';
            $checks['checks']['redis'] = 'error: ' . $e->getMessage();
        }

        $statusCode = $checks['status'] === 'ok' ? 200 : 503;

        return $this->response->json($checks)->withStatus($statusCode);
    }

    public function metrics(): PsrResponseInterface
    {
        $metrics = $this->metricsService->getAllMetrics();
        
        // Formato compatível com Prometheus
        $prometheusFormat = [];
        
        // Lista de métricas que são histogramas
        $histogramMetrics = [];
        
        foreach ($metrics as $metricName => $values) {
            foreach ($values as $value) {
                $labels = $value['labels'] ?? [];
                $labelStr = '';
                
                if (!empty($labels)) {
                    $labelParts = [];
                    foreach ($labels as $key => $val) {
                        $labelParts[] = sprintf('%s="%s"', $key, addslashes((string) $val));
                    }
                    $labelStr = '{' . implode(',', $labelParts) . '}';
                }
                
                // Verificar se é histograma
                $isHistogram = (str_contains($metricName, '_time_') || 
                               str_contains($metricName, '_duration_') || 
                               (str_contains($metricName, '_amount') && !str_ends_with($metricName, '_total')));
                
                if ($isHistogram) {
                    // Armazenar para processar depois (evitar duplicatas)
                    $histogramKey = $metricName . $labelStr;
                    if (!isset($histogramMetrics[$histogramKey])) {
                        $histogramMetrics[$histogramKey] = [
                            'name' => $metricName,
                            'labels' => $labels,
                            'labelStr' => $labelStr,
                        ];
                    }
                } else {
                    // Métricas normais (counters, gauges)
                    $prometheusFormat[] = sprintf(
                        '%s%s %s',
                        $metricName,
                        $labelStr,
                        $value['value'] ?? 0
                    );
                }
            }
        }
        
        // Processar histogramas separadamente
        // Buscar todas as chaves de histograma conhecidas diretamente do Redis
        $histogramNames = ['http_request_duration_seconds', 'withdraw_processing_time_seconds', 'withdraw_creation_time_seconds', 'withdraw_amount'];
        $processedHistograms = [];
        
        foreach ($histogramNames as $metricName) {
            // Buscar todas as chaves :count para esta métrica
            $countKeys = $this->redis->keys("metrics:{$metricName}*:count");
            
            foreach ($countKeys as $countKey) {
                // Extrair hash de labels
                $keyWithoutPrefix = str_replace("metrics:{$metricName}:", '', $countKey);
                $labelHash = str_replace(':count', '', $keyWithoutPrefix);
                
                // Buscar labels
                $labelKey = "metrics_labels:{$metricName}:{$labelHash}";
                $labelsJson = $this->redis->get($labelKey);
                $labels = $labelsJson ? (json_decode($labelsJson, true) ?: []) : [];
                
                // Construir label string
                $labelStr = '';
                if (!empty($labels)) {
                    $labelParts = [];
                    foreach ($labels as $k => $v) {
                        $labelParts[] = sprintf('%s="%s"', $k, addslashes((string) $v));
                    }
                    $labelStr = '{' . implode(',', $labelParts) . '}';
                }
                
                // Obter valores
                $sumKey = str_replace(':count', ':sum', $countKey);
                $sum = (float) ($this->redis->get($sumKey) ?? 0);
                $count = (int) ($this->redis->get($countKey) ?? 0);
                
                // Expor _sum e _count (sempre, mesmo se 0 para manter consistência)
                $histogramKey = $metricName . $labelStr;
                if (!isset($processedHistograms[$histogramKey])) {
                    $prometheusFormat[] = sprintf(
                        '%s_sum%s %s',
                        $metricName,
                        $labelStr,
                        $sum
                    );
                    
                    $prometheusFormat[] = sprintf(
                        '%s_count%s %s',
                        $metricName,
                        $labelStr,
                        $count
                    );
                    
                    $processedHistograms[$histogramKey] = true;
                }
            }
        }
        
        // Processar histogramas da lista (fallback)
        foreach ($histogramMetrics as $histogram) {
            $histogramKey = $histogram['name'] . $histogram['labelStr'];
            if (!isset($processedHistograms[$histogramKey])) {
                $stats = $this->metricsService->getHistogramStats($histogram['name'], $histogram['labels']);
                
                $prometheusFormat[] = sprintf(
                    '%s_sum%s %s',
                    $histogram['name'],
                    $histogram['labelStr'],
                    $stats['sum'] ?? 0
                );
                
                $prometheusFormat[] = sprintf(
                    '%s_count%s %s',
                    $histogram['name'],
                    $histogram['labelStr'],
                    $stats['count'] ?? 0
                );
                
                $processedHistograms[$histogramKey] = true;
            }
        }
        
        return $this->response
            ->withHeader('Content-Type', 'text/plain; version=0.0.4')
            ->withBody(new SwooleStream(implode("\n", $prometheusFormat) . "\n"));
    }

    public function metricsJson(): PsrResponseInterface
    {
        $metrics = $this->metricsService->getAllMetrics();
        
        // Adicionar estatísticas de histogramas
        $enrichedMetrics = [];
        
        foreach ($metrics as $metricName => $values) {
            $enrichedMetrics[$metricName] = [];
            
            foreach ($values as $value) {
                $metricData = [
                    'labels' => $value['labels'] ?? [],
                    'value' => $value['value'],
                ];
                
                // Se for histograma, adicionar estatísticas
                if (str_contains($metricName, '_time_') || str_contains($metricName, '_duration_')) {
                    $stats = $this->metricsService->getHistogramStats($metricName, $value['labels'] ?? []);
                    $metricData['stats'] = $stats;
                }
                
                $enrichedMetrics[$metricName][] = $metricData;
            }
        }
        
        return $this->response->json([
            'timestamp' => date('c'),
            'metrics' => $enrichedMetrics,
        ]);
    }
}


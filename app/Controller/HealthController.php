<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\MetricsService;
use Hyperf\DbConnection\Db;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\GetMapping;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

#[Controller]
class HealthController
{
    public function __construct(
        private MetricsService $metricsService,
        private Redis $redis,
        private ResponseInterface $response,
    ) {
    }

    #[GetMapping(path: '/health')]
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

    #[GetMapping(path: '/metrics')]
    public function metrics(): PsrResponseInterface
    {
        $metrics = $this->metricsService->getAllMetrics();
        
        // Formato compatível com Prometheus
        $prometheusFormat = [];
        
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
                
                $prometheusFormat[] = sprintf(
                    '%s%s %s',
                    $metricName,
                    $labelStr,
                    $value['value'] ?? 0
                );
            }
        }
        
        return $this->response
            ->withHeader('Content-Type', 'text/plain; version=0.0.4')
            ->withBody(new SwooleStream(implode("\n", $prometheusFormat) . "\n"));
    }

    #[GetMapping(path: '/metrics/json')]
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


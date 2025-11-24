<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MetricsService;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class MetricsCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
        protected MetricsService $metricsService,
    ) {
        parent::__construct('metrics');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Exibir métricas do sistema');
        $this->addOption('json', 'j', InputOption::VALUE_NONE, 'Formato JSON');
    }

    public function handle()
    {
        $json = $this->input->getOption('json');
        $metrics = $this->metricsService->getAllMetrics();

        if ($json) {
            $this->line(json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        if (empty($metrics)) {
            $this->warn('Nenhuma métrica encontrada.');
            return 0;
        }

        $this->info('📈 Métricas do Sistema');
        $this->line('');

        foreach ($metrics as $metricName => $values) {
            $this->line("📊 {$metricName}:");
            
            foreach ($values as $value) {
                $labels = !empty($value['labels']) 
                    ? ' (' . implode(', ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($value['labels']), $value['labels'])) . ')'
                    : '';
                
                $val = $value['value'] ?? 0;
                $this->line("   {$val}{$labels}");
            }
            
            $this->line('');
        }

        return 0;
    }
}


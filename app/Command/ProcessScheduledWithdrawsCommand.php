<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawService;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Crontab\Annotation\Crontab;
use Psr\Container\ContainerInterface;

#[Command]
class ProcessScheduledWithdrawsCommand extends HyperfCommand
{
    public function __construct(
        protected ContainerInterface $container,
    ) {
        parent::__construct('withdraw:process-scheduled');
    }

    public function configure(): void
    {
        $this->setDescription('Process scheduled withdraws');
    }

    #[Crontab(name: 'ProcessScheduledWithdraws', rule: '* * * * *', memo: 'Process scheduled withdraws every minute')]
    public function handle(): int
    {
        $this->info('⏰ [CRON] Processing scheduled withdraws...');

        $withdrawService = $this->container->get(WithdrawService::class);
        $processed = $withdrawService->processScheduledWithdraws();

        if ($processed > 0) {
            $this->info("✅ [CRON] Processed {$processed} scheduled withdraw(s).");
        } else {
            $this->info("ℹ️  [CRON] No scheduled withdraws to process.");
        }

        return 0;
    }
}


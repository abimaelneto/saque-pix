<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawService;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Crontab\Annotation\Crontab;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
    public function handle(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('⏰ [CRON] Processing scheduled withdraws...');

        $withdrawService = $this->container->get(WithdrawService::class);
        $processed = $withdrawService->processScheduledWithdraws();

        if ($processed > 0) {
            $output->writeln("✅ [CRON] Processed {$processed} scheduled withdraw(s).");
        } else {
            $output->writeln("ℹ️  [CRON] No scheduled withdraws to process.");
        }

        return 0;
    }
}


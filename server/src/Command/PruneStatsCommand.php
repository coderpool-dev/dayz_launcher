<?php

namespace App\Command;

use App\Stats\StatsQuery;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Удаляет статистику с IP-адресами старше STATS_RETENTION_DAYS (запускается по cron раз в сутки).
 */
#[AsCommand(name: 'app:stats:prune', description: 'Удалить старую статистику по IP')]
final class PruneStatsCommand extends Command
{
    public function __construct(
        private readonly StatsQuery $stats,
        #[Autowire('%env(int:STATS_RETENTION_DAYS)%')]
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->stats->prune(max(1, $this->retentionDays));
        $output->writeln(sprintf('Удалено записей старше %d дней: %d', $this->retentionDays, $deleted));

        return Command::SUCCESS;
    }
}

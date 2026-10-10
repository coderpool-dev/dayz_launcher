<?php

namespace App\Command;

use App\ServerList\ServerListProviderInterface;
use App\ServerList\ServerListUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Запускается по cron раз в 2 минуты: список обновляется заранее, и запросы
 * лаунчеров никогда не ждут загрузки из DZSA/BattleMetrics.
 */
#[AsCommand(name: 'app:servers:refresh', description: 'Обновить список серверов из DZSA/BattleMetrics')]
final class RefreshServersCommand extends Command
{
    public function __construct(private readonly ServerListProviderInterface $provider)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $status = $this->provider->ensureFresh(force: true);
        } catch (ServerListUnavailableException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('Статус: ' . $status, OutputInterface::VERBOSITY_VERBOSE);

        return $status === 'refreshed' ? Command::SUCCESS : Command::FAILURE;
    }
}

<?php

namespace App\Command;

use App\Geo\CountryDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Скачивает базу DB-IP «IP to Country Lite» и пересобирает файл для CountryDatabase.
 * DB-IP выкладывает новую базу в начале месяца; cron запускает команду раз в месяц.
 */
#[AsCommand(name: 'app:geoip:update', description: 'Обновить базу стран по IP (DB-IP Lite)')]
final class UpdateGeoIpCommand extends Command
{
    private const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CountryDatabase $countries,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $archive = tempnam(sys_get_temp_dir(), 'dbip');
        try {
            // В первые дни месяца файла за новый месяц может ещё не быть — тогда берём прошлый.
            foreach ([new \DateTimeImmutable('first day of this month'), new \DateTimeImmutable('first day of last month')] as $month) {
                $url = sprintf(self::URL, $month->format('Y-m'));
                if ($this->download($url, $archive)) {
                    $ranges = CountryDatabase::build(self::lines($archive), $this->countries->path());
                    $output->writeln("Диапазонов: {$ranges} ({$url})", OutputInterface::VERBOSITY_VERBOSE);

                    return Command::SUCCESS;
                }
            }

            $output->writeln('<error>Не удалось скачать базу DB-IP</error>');

            return Command::FAILURE;
        } finally {
            @unlink($archive);
        }
    }

    private function download(string $url, string $target): bool
    {
        $response = $this->httpClient->request('GET', $url, ['timeout' => 30, 'max_duration' => 300]);
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $file = fopen($target, 'wb');
        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                fwrite($file, $chunk->getContent());
            }
        } finally {
            fclose($file);
        }

        return filesize($target) > 0;
    }

    /** @return \Generator<string> */
    private static function lines(string $archive): \Generator
    {
        $file = gzopen($archive, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Cannot open GeoIP archive');
        }

        try {
            while (($line = gzgets($file)) !== false) {
                yield $line;
            }
        } finally {
            gzclose($file);
        }
    }
}

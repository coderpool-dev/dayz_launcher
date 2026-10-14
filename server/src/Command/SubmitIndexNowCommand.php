<?php

namespace App\Command;

use App\Seo\SeoUrls;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Отправляет адреса страниц в IndexNow (Яндекс и Bing): новые серверы попадают в поиск
 * без ожидания обхода sitemap. Запускается cron раз в сутки.
 */
#[AsCommand(name: 'app:seo:indexnow', description: 'Submit indexable URLs to IndexNow (Yandex)')]
final class SubmitIndexNowCommand extends Command
{
    /** Лимит протокола на один запрос. */
    private const BATCH = 10000;

    public function __construct(
        private readonly SeoUrls $urls,
        private readonly HttpClientInterface $http,
        #[Autowire('%env(INDEXNOW_KEY)%')] private readonly string $key,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $locations = array_column($this->urls->all(), 'loc');
        foreach (array_chunk($locations, self::BATCH) as $batch) {
            $response = $this->http->request('POST', 'https://yandex.com/indexnow', [
                'json' => ['host' => parse_url(SeoUrls::HOST, PHP_URL_HOST), 'key' => $this->key,
                    'keyLocation' => SeoUrls::HOST . '/indexnow.txt', 'urlList' => $batch],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $output->writeln(sprintf('IndexNow: %d адресов, ответ %d', count($batch), $status));
            if ($status >= 400) {
                $output->writeln($response->getContent(false));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}

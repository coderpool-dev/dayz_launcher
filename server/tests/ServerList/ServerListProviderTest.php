<?php

namespace App\Tests\ServerList;

use App\Geo\CountryDatabase;
use App\ServerList\Deadline;
use App\ServerList\ServerListProvider;
use App\ServerList\ServerListPublisher;
use App\ServerList\ServerListStore;
use App\ServerList\ServerListUnavailableException;
use App\ServerList\ServerNormalizer;
use App\ServerList\UpstreamSource;
use App\ServerList\ZeroPlayerTracker;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * 24.10 основной список DZSA не скачался, а 6 серверов из listings опубликовались вместо ~2600:
 * мониторинг и тысячи страниц серверов опустели. Обрывок при ошибках источников не публикуется.
 */
final class ServerListProviderTest extends KernelTestCase
{
    private ServerListStore $store;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->store = static::getContainer()->get(ServerListStore::class);
        $directory = static::getContainer()->getParameter('app.data_dir') . '/servers';
        (new Filesystem())->remove($directory);
    }

    private function provider(int $count, array $errors = []): ServerListProvider
    {
        $upstream = new class($count, $errors) implements UpstreamSource {
            public function __construct(private readonly int $count, private readonly array $errors)
            {
            }

            public function isBattlemetricsEnabled(): bool
            {
                return false;
            }

            public function fetchDzsa(Deadline $deadline, array &$errors): array
            {
                array_push($errors, ...$this->errors);

                return array_map(static fn (int $i) => ['endpoint' => ['ip' => '10.1.' . intdiv($i, 250) . '.' . ($i % 250 + 1), 'port' => 2302],
                    'gamePort' => 2302, 'name' => "Server {$i}", 'map' => 'chernarusplus', 'players' => 5, 'maxPlayers' => 60], range(1, $this->count));
            }

            public function fetchBattlemetrics(int $maxPages, Deadline $deadline, array &$errors): array
            {
                return ['items' => [], 'pagesFetched' => 0, 'hasMore' => false, 'truncated' => false];
            }
        };
        $container = static::getContainer();

        return new ServerListProvider($upstream, $container->get(ServerNormalizer::class), $container->get(ZeroPlayerTracker::class),
            $this->store, $container->get(ServerListPublisher::class), new NullLogger(), $container->get(CountryDatabase::class));
    }

    private const TIMEOUT = ['url' => 'https://dayzsalauncher.com/api/v2/launcher/servers/dayz', 'status' => 0,
        'error' => 'Operation timed out after 24001 milliseconds with 1846054 bytes received'];

    public function testTruncatedListAfterUpstreamErrorKeepsThePreviousOne(): void
    {
        self::assertSame('refreshed', $this->provider(100)->ensureFresh());
        self::assertSame(100, $this->store->readStats()['servers']);

        try {
            $this->provider(6, [self::TIMEOUT])->ensureFresh();
            self::fail('A truncated list must not be published');
        } catch (ServerListUnavailableException) {
        }

        self::assertSame(100, $this->store->readStats()['servers']);
        self::assertCount(100, $this->store->readSnapshot()['servers']);
    }

    public function testSmallerListWithoutErrorsIsStillPublished(): void
    {
        $this->provider(100)->ensureFresh();
        $this->provider(6)->ensureFresh();

        self::assertSame(6, $this->store->readStats()['servers']);
    }

    public function testModestDropWithErrorsIsPublished(): void
    {
        $this->provider(100)->ensureFresh();
        $this->provider(60, [self::TIMEOUT])->ensureFresh();

        self::assertSame(60, $this->store->readStats()['servers']);
    }

    public function testFirstListIsPublishedEvenWithErrors(): void
    {
        $this->provider(6, [self::TIMEOUT])->ensureFresh();

        self::assertSame(6, $this->store->readStats()['servers']);
    }
}

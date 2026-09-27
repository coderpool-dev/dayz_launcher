<?php

namespace App\Tests\Controller;

use App\Entity\SponsorServer;
use App\ServerList\ServerListPublisher;
use App\ServerList\ServerListStore;
use App\Tests\DatabaseWebTestCase;

final class ServersApiTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Свежий снимок — провайдер не пойдёт во внешние источники.
        static::getContainer()->get(ServerListStore::class)->writeSnapshot([
            self::server('Big server', '1.1.1.1', 2302, 50),
            self::server('Sponsored server', '5.5.5.5', 2402, 3, gamePort: 2302),
            self::server('Livonia server', '7.7.7.7', 2302, 10, map: 'Livonia'),
        ], [], ['dzsaFetched' => 3], time());
        $this->publish();
    }

    public function testReturnsServersInLauncherFormat(): void
    {
        $this->client->request('GET', '/api/servers');

        self::assertResponseIsSuccessful();
        $json = $this->jsonResponse();
        self::assertTrue($json['success']);
        self::assertSame('goida', $json['source']);
        self::assertSame(3, $json['count']);
        self::assertSame(63, $json['stats']['totalPlayers']);
        self::assertSame(['Big server', 'Livonia server', 'Sponsored server'], array_column($json['servers'], 'name'));
    }

    public function testSponsorFromAdminIsPinnedToTop(): void
    {
        $sponsor = new SponsorServer();
        $sponsor->setTitle('Партнёр');
        $sponsor->setIp('5.5.5.5');
        $sponsor->setPort(2302); // игровой порт — тоже подходит
        $sponsor->setPriority(5);
        $this->persist($sponsor);
        $this->publish();

        $this->client->request('GET', '/api/servers');

        $first = $this->jsonResponse()['servers'][0];
        self::assertSame('Sponsored server', $first['name']);
        self::assertTrue($first['sponsor']);
        self::assertSame(5, $first['sponsorPriority']);
    }

    public function testExpiredSponsorIsIgnored(): void
    {
        $sponsor = new SponsorServer();
        $sponsor->setTitle('Истёк');
        $sponsor->setIp('5.5.5.5');
        $sponsor->setPort(2402);
        $sponsor->setActiveUntil(new \DateTimeImmutable('-1 day'));
        $this->persist($sponsor);
        $this->publish();

        $this->client->request('GET', '/api/servers');

        self::assertSame('Big server', $this->jsonResponse()['servers'][0]['name']);
    }

    public function testSearchAndLimit(): void
    {
        $this->client->request('GET', '/api/servers', ['search' => 'livonia']);
        self::assertSame(['Livonia server'], array_column($this->jsonResponse()['servers'], 'name'));

        $this->client->request('GET', '/api/servers', ['limit' => 1]);
        self::assertSame(1, $this->jsonResponse()['count']);
    }

    public function testRefreshWithoutKeyServesCache(): void
    {
        $this->client->request('GET', '/api/servers', ['refresh' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame('hit', $this->jsonResponse()['meta']['cacheStatus']);
    }

    /** В админке ответ пересобирает CRUD спонсоров; здесь спонсоры добавляются напрямую. */
    private function publish(): void
    {
        static::getContainer()->get(ServerListPublisher::class)->publish();
    }

    private static function server(string $name, string $ip, int $queryPort, int $players, string $map = 'chernarusplus', ?int $gamePort = null): array
    {
        return [
            'id' => crc32($name), 'source' => 'dzsa', 'name' => $name, 'displayName' => $name,
            'ip' => $ip, 'port' => $gamePort ?? $queryPort, 'gamePort' => $gamePort ?? $queryPort, 'queryPort' => $queryPort,
            'map' => $map, 'mapName' => $map, 'players' => $players, 'maxPlayers' => 60, 'online' => true,
            'mods' => [], 'modIds' => [], 'sponsor' => false, 'sponsorPriority' => 0,
        ];
    }
}

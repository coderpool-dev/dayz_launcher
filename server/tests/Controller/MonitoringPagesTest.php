<?php

namespace App\Tests\Controller;

use App\ServerList\ServerListPublisher;
use App\ServerList\ServerListSnapshot;
use App\Tests\DatabaseWebTestCase;

final class MonitoringPagesTest extends DatabaseWebTestCase
{
    private array $servers;

    protected function setUp(): void
    {
        parent::setUp();
        $servers = [
            ['name' => 'Frosty PvE | discord.gg/frosty', 'ip' => '1.2.3.4', 'queryPort' => 2302, 'gamePort' => 2300,
                'players' => 40, 'maxPlayers' => 60, 'online' => true, 'map' => 'namalsk', 'mapName' => 'Namalsk',
                'version' => '1.29', 'perspective' => '1pp', 'modIds' => ['1559212036'], 'mods' => ['CF']],
            ['name' => 'Raid Night PvP', 'ip' => '5.6.7.8', 'queryPort' => 27016, 'gamePort' => 2302,
                'players' => 12, 'maxPlayers' => 50, 'online' => true, 'map' => 'chernarusplus', 'mapName' => 'Chernarus'],
            ['name' => 'Empty Vanilla', 'ip' => '9.9.9.9', 'queryPort' => 2302, 'gamePort' => 2302,
                'players' => 0, 'maxPlayers' => 60, 'online' => true, 'map' => 'chernarusplus', 'mapName' => 'Chernarus'],
        ];
        // Пять серверов на Namalsk — карта получает свою страницу в sitemap.
        foreach (range(1, 4) as $i) {
            $servers[] = ['name' => "Namalsk {$i}", 'ip' => "10.0.0.{$i}", 'queryPort' => 2302, 'gamePort' => 2302,
                'players' => $i, 'maxPlayers' => 60, 'online' => true, 'map' => 'namalsk', 'mapName' => 'Namalsk'];
        }
        $this->servers = $servers;
        $this->publish($servers, time());
    }

    private function publish(array $servers, int $at): void
    {
        static::getContainer()->get(ServerListPublisher::class)->publish(new ServerListSnapshot($servers, [], [], $at, 'refreshed'));
    }

    /** Список без сервера 1.2.3.4 (выключили, или его спрятали пустым), а до этого он был в сети в $seenAt. */
    private function frostyDisappearedAfter(int $seenAt): void
    {
        $this->publish($this->servers, $seenAt);
        $this->publish(array_values(array_filter($this->servers, static fn (array $server) => $server['ip'] !== '1.2.3.4')), time());
    }

    public function testServerMissingFromTheListKeepsItsIndexedPageForAWeek(): void
    {
        $this->frostyDisappearedAfter(time() - 3600);

        $crawler = $this->client->request('GET', '/monitoring/server/1.2.3.4-2302');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content^="index"]');
        self::assertSelectorTextContains('h1', 'Frosty PvE');
        self::assertStringContainsString('Сейчас сервера нет в мониторинге', $crawler->filter('.server-offline')->text());
        self::assertStringContainsString('1.2.3.4:2300', $crawler->filter('main')->text());

        $this->client->request('GET', '/sitemap.xml');
        self::assertStringContainsString('https://prepiskadayz.ru/monitoring/server/1.2.3.4-2302', $this->client->getResponse()->getContent());
        $crawler = $this->client->request('GET', '/monitoring/map/namalsk');
        self::assertStringNotContainsString('Frosty PvE', $crawler->filter('main')->text(), 'Пропавший сервер не попадает в списки');
    }

    public function testMissingServerThatWasNeverWorthIndexingStaysNoindex(): void
    {
        $this->publish(array_values(array_filter($this->servers, static fn (array $server) => $server['ip'] !== '9.9.9.9')), time());

        $this->client->request('GET', '/monitoring/server/9.9.9.9-2302');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
    }

    public function testMissingServerLeavesTheIndexAfterAWeekAndDisappearsAfterTwo(): void
    {
        $this->frostyDisappearedAfter(time() - 8 * 86400);
        $this->client->request('GET', '/monitoring/server/1.2.3.4-2302');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');

        $this->frostyDisappearedAfter(time() - 15 * 86400);
        $this->client->request('GET', '/monitoring/server/1.2.3.4-2302');
        self::assertResponseStatusCodeSame(404);
    }

    public function testServerPageIsRenderedForSearchEngines(): void
    {
        $crawler = $this->client->request('GET', '/monitoring/server/1.2.3.4-2302');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Frosty PvE');
        self::assertStringStartsWith('Frosty PvE — сервер DayZ Namalsk', $crawler->filter('title')->text());
        self::assertSelectorExists('link[rel="canonical"][href="https://prepiskadayz.ru/monitoring/server/1.2.3.4-2302"]');
        self::assertSelectorExists('meta[name="robots"][content^="index"]');
        self::assertStringContainsString('1.2.3.4:2300', $crawler->filter('main')->text());
        self::assertSelectorExists('a[href="https://steamcommunity.com/sharedfiles/filedetails/?id=1559212036"]');
        $schema = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('GameServer', $schema['@graph'][1]['@type']);
        self::assertSame(40, $schema['@graph'][1]['playersOnline']);
    }

    public function testEmptyServerIsNotIndexedAndUnknownServerIs404(): void
    {
        $this->client->request('GET', '/monitoring/server/9.9.9.9-2302');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
        $this->client->request('GET', '/monitoring/server/8.8.8.8-2302');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorExists('meta[name="robots"][content="noindex, follow"]');
    }

    public function testMapAndModePagesListOnlyMatchingServers(): void
    {
        $crawler = $this->client->request('GET', '/monitoring/map/namalsk');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Серверы DayZ на карте Namalsk');
        self::assertSelectorExists('link[rel="canonical"][href="https://prepiskadayz.ru/monitoring/map/namalsk"]');
        self::assertCount(5, $crawler->filter('.monitor-table tbody tr'));
        self::assertStringContainsString('на них 50 игроков', $crawler->filter('.monitor-intro')->text());

        $crawler = $this->client->request('GET', '/monitoring/pvp');
        self::assertResponseIsSuccessful();
        self::assertSame(['Raid Night PvP'], $crawler->filter('.monitor-table a.server-name')->each(fn ($a) => $a->text()));

        $this->client->request('GET', '/monitoring/map/unknown-map');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNasdaraPageHasItsOwnTitleAndDescription(): void
    {
        $servers = [];
        foreach (range(1, 6) as $i) {
            $servers[] = ['name' => "Badlands {$i}", 'ip' => "20.0.0.{$i}", 'queryPort' => 2302, 'gamePort' => 2302,
                'players' => 10 * $i, 'maxPlayers' => 100, 'online' => true, 'map' => 'nasdara', 'mapName' => 'Nasdara'];
        }
        static::getContainer()->get(ServerListPublisher::class)->publish(new ServerListSnapshot($servers, [], [], time(), 'refreshed'));

        $crawler = $this->client->request('GET', '/monitoring/map/nasdara');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Серверы DayZ Nasdara (Badlands)', $crawler->filter('title')->text());
        self::assertSelectorTextContains('h1', 'Серверы DayZ на карте Nasdara');
        self::assertStringContainsString('дополнения DayZ Badlands', $crawler->filter('.monitor-intro')->text());
        self::assertSame('Badlands 6', $crawler->filter('.monitor-table a.server-name')->first()->text());
    }

    public function testMonitoringShipsFirstPageWithoutJavascript(): void
    {
        $crawler = $this->client->request('GET', '/monitoring');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Сервера DayZ: мониторинг онлайн');
        self::assertSame('/monitoring/server/1.2.3.4-2302', $crawler->filter('[data-servers] a.server-name')->first()->attr('href'));
        self::assertSelectorExists('.monitor-links a[href="/monitoring/map/namalsk"]');
        self::assertSelectorExists('.monitor-links a[href="/monitoring/pve"]');
        self::assertSelectorTextContains('[data-count]', 'Серверов: 7');
        self::assertSelectorTextContains('[data-count]', 'Игроков: 62');
        self::assertSelectorTextContains('[data-updated]', 'Данные на');
        self::assertSelectorExists('.monitor-table[aria-busy="false"]');
        self::assertSelectorExists('.monitor-shortcuts a[href="/monitoring/pve"]');
        self::assertSelectorExists('.monitor-shortcuts a[href="/monitoring/pvp"]');
        $schema = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        $items = $schema['@graph'][1]['mainEntity']['itemListElement'];
        self::assertCount($crawler->filter('[data-servers] a.server-name')->count(), $items);
        foreach ($items as $i => $item) {
            self::assertSame($crawler->filter('[data-servers] a.server-name')->eq($i)->text(), $item['name']);
            self::assertSame('https://prepiskadayz.ru' . $crawler->filter('[data-servers] a.server-name')->eq($i)->attr('href'), $item['url']);
        }
    }

    public function testMonitoringPaginationIsCrawlableAndEachPageHasItsOwnCanonical(): void
    {
        $servers = [];
        foreach (range(1, 61) as $i) {
            $servers[] = ['name' => "Server {$i}", 'ip' => "192.0.2.{$i}", 'queryPort' => 2302,
                'gamePort' => 2300, 'players' => 100 - $i, 'maxPlayers' => 100, 'online' => true,
                'map' => 'namalsk', 'mapName' => 'Namalsk'];
        }
        static::getContainer()->get(ServerListPublisher::class)->publish(new ServerListSnapshot($servers, [], [], time(), 'refreshed'));
        $crawler = $this->client->request('GET', '/monitoring');
        self::assertCount(30, $crawler->filter('[data-servers] tr'));
        self::assertSelectorExists('a[data-next][href="/monitoring?page=2"]');
        self::assertSelectorExists('[data-prev][aria-disabled="true"]:not([href])');

        $crawler = $this->client->request('GET', '/monitoring?page=2');
        self::assertResponseIsSuccessful();
        self::assertSame('Server 31', $crawler->filter('[data-servers] a.server-name')->first()->text());
        self::assertSelectorExists('link[rel="canonical"][href="https://prepiskadayz.ru/monitoring?page=2"]');
        self::assertSelectorExists('a[data-prev][href="/monitoring"]');
        self::assertSelectorExists('a[data-next][href="/monitoring?page=3"]');
        $schema = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(31, $schema['@graph'][1]['mainEntity']['itemListElement'][0]['position']);

        $this->client->request('GET', '/monitoring?page=3&lang=en');
        self::assertSelectorExists('link[rel="canonical"][href="https://prepiskadayz.ru/monitoring?page=3&lang=en"]');
        self::assertSelectorExists('link[hreflang="ru"][href="https://prepiskadayz.ru/monitoring?page=3&lang=ru"]');
        self::assertSelectorExists('[data-next][aria-disabled="true"]:not([href])');
        foreach (['4', '0', '-1', 'invalid', '1&page[]=2'] as $page) {
            $this->client->request('GET', '/monitoring?page=' . $page);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testSitemapListsCollectionsAndLiveServersOnly(): void
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $xml = simplexml_load_string($this->client->getResponse()->getContent());
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locations = array_map('strval', $xml->xpath('//s:loc'));
        self::assertContains('https://prepiskadayz.ru/monitoring/map/namalsk', $locations);
        self::assertNotContains('https://prepiskadayz.ru/monitoring/map/chernarus', $locations, 'Меньше пяти серверов — без страницы карты');
        self::assertContains('https://prepiskadayz.ru/monitoring/pve', $locations);
        self::assertContains('https://prepiskadayz.ru/monitoring/server/1.2.3.4-2302', $locations);
        self::assertNotContains('https://prepiskadayz.ru/monitoring/server/9.9.9.9-2302', $locations);

        $this->client->request('GET', '/indexnow.txt');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->client->getResponse()->getContent());
    }
}

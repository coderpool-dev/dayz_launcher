<?php

namespace App\Tests\Controller;

use App\ServerList\ServerListPublisher;
use App\ServerList\ServerListSnapshot;
use App\ServerList\ServerPopulationHistory;
use App\Tests\DatabaseWebTestCase;

final class MonitoringApiTest extends DatabaseWebTestCase
{
    public function testEnrichesMonitoringWithoutChangingLauncherContract(): void
    {
        $server = ['name' => 'PvE Bitterroot', 'ip' => '1.2.3.4', 'queryPort' => 2302, 'gamePort' => 2300,
            'players' => 20, 'maxPlayers' => 60, 'online' => true, 'mapName' => 'Bitterroot', 'map' => 'bitterroot',
            'version' => '1.29.163709', 'modIds' => ['123'], 'mods' => ['CF']];
        static::getContainer()->get(ServerListPublisher::class)->publish(new ServerListSnapshot([$server], [], [], time(), 'refreshed'));
        $this->client->request('GET', '/api/monitoring');
        self::assertResponseIsSuccessful();
        $result = $this->jsonResponse()['servers'][0];
        self::assertSame('1.29.163709', $result['version']);
        self::assertSame('Bitterroot', $result['mapName']);
        self::assertSame(['pve'], $result['categories']['modes']);
        self::assertSame(1, $result['modCount']);
        self::assertTrue($this->jsonResponse()['history']['fresh']);
        $path = static::getContainer()->get(\App\ServerList\ServerListStore::class)->publicPayloadPath();
        $launcher = json_decode(file_get_contents($path), true)['servers'][0];
        self::assertArrayNotHasKey('categories', $launcher);
        self::assertArrayNotHasKey('population', $launcher);
    }

    public function testHistoryReturnsGapsAndValidatesParameters(): void
    {
        static::getContainer()->get(ServerPopulationHistory::class)->record([
            ['ip' => '1.2.3.4', 'queryPort' => 2302, 'online' => true, 'players' => 12, 'maxPlayers' => 60],
        ], time());
        $this->client->request('GET', '/api/monitoring/history?server=1.2.3.4:2302&days=7');
        self::assertResponseIsSuccessful();
        $points = $this->jsonResponse()['points'];
        self::assertCount(168, $points);
        self::assertNull($points[0]['players']);
        self::assertEquals(12, $points[167]['players']);
        $this->client->request('GET', '/api/monitoring/history?server=invalid');
        self::assertResponseStatusCodeSame(400);
    }

    public function testMissingListReportsUnavailable(): void
    {
        $this->client->request('GET', '/api/monitoring');
        self::assertResponseStatusCodeSame(503);
    }

    public function testSummaryUsesAnObjectForEmptyServerDictionary(): void
    {
        $this->client->request('GET', '/api/monitoring/summary');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"servers":{}', $this->client->getResponse()->getContent());
    }
}

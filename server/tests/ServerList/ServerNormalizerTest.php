<?php

namespace App\Tests\ServerList;

use App\ServerList\ServerNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerNormalizerTest extends TestCase
{
    private ServerNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ServerNormalizer();
    }

    public function testNormalizesDzsaServer(): void
    {
        $server = $this->normalizer->normalizeDzsa([
            'name' => 'PODPIVAS #11 LIVONIA',
            'endpoint' => ['ip' => '185.207.214.122', 'port' => 2602],
            'gamePort' => 2402,
            'map' => 'enoch',
            'players' => 11,
            'maxPlayers' => 90,
            'time' => '12:30',
            'sponsor' => true,
            'mods' => [
                ['steamWorkshopId' => 1559212036, 'name' => 'CF'],
                ['steamWorkshopId' => '2017015030', 'name' => ''],
                ['steamWorkshopId' => '1559212036', 'name' => 'CF duplicate'],
                ['steamWorkshopId' => 'broken', 'name' => 'Broken'],
            ],
        ]);

        self::assertSame(1752401238, $server['id']);
        self::assertSame(2602, $server['queryPort']);
        self::assertSame(2402, $server['gamePort']);
        self::assertSame('Livonia', $server['mapName']);
        self::assertSame('12:30', $server['serverTime']);
        self::assertSame(['1559212036', '2017015030'], $server['modIds']);
        self::assertSame(['CF', 'Workshop 2017015030'], $server['mods']);
        self::assertFalse($server['sponsor'], 'Спонсорство DZSA игнорируется — спонсоров задаёт админка.');
    }

    public function testFiltersMirrorServers(): void
    {
        self::assertNull($this->normalizer->normalizeDzsa([
            'name' => 'Fake copy', 'endpoint' => ['ip' => '31.77.142.61', 'port' => 55069], 'gamePort' => 55068, 'maxPlayers' => 127,
        ]));

        self::assertNotNull($this->normalizer->normalizeDzsa([
            'name' => 'Real server on same subnet', 'endpoint' => ['ip' => '31.77.142.61', 'port' => 2302], 'maxPlayers' => 127,
        ]));
    }

    public function testShortensDisplayName(): void
    {
        $server = $this->normalizer->normalizeDzsa(['name' => 'Russian Classic Deathmatch | 24/7 | Chernarus', 'ip' => '1.2.3.4', 'queryPort' => 27016]);

        self::assertSame('Russian Classic Deathmatch', $server['displayName']);
    }

    public function testRejectsServerWithoutAddress(): void
    {
        self::assertNull($this->normalizer->normalizeDzsa(['name' => 'No address']));
    }

    #[DataProvider('maps')]
    public function testMapNormalization(string $map, string $key, string $name): void
    {
        self::assertSame($key, ServerNormalizer::mapKey($map));
        self::assertSame($name, ServerNormalizer::mapName($map));
    }

    public static function maps(): iterable
    {
        yield ['livonia', 'enoch', 'Livonia'];
        yield ['', 'chernarusplus', 'Chernarus'];
        yield ['deer isle', 'deerisle', 'Deer Isle'];
        yield ['pripyat', 'pripyat', 'Pripyat'];
    }
}

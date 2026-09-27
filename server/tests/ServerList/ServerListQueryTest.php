<?php

namespace App\Tests\ServerList;

use App\ServerList\ServerListQuery;
use App\ServerList\ZeroPlayerTracker;
use PHPUnit\Framework\TestCase;

final class ServerListQueryTest extends TestCase
{
    public function testSponsorsFirstByPriorityThenByPlayers(): void
    {
        $servers = [
            ['name' => 'Big', 'players' => 100],
            ['name' => 'Sponsor low', 'players' => 1, 'sponsor' => true, 'sponsorPriority' => 1],
            ['name' => 'Small', 'players' => 5],
            ['name' => 'Sponsor high', 'players' => 0, 'sponsor' => true, 'sponsorPriority' => 10],
        ];

        $result = (new ServerListQuery())->apply($servers);

        self::assertSame(['Sponsor high', 'Sponsor low', 'Big', 'Small'], array_column($result, 'name'));
    }

    public function testSearchByNameMapAndMods(): void
    {
        $servers = [
            ['name' => 'Alpha', 'mapName' => 'Livonia', 'mods' => ['CF']],
            ['name' => 'Beta', 'mapName' => 'Chernarus', 'mods' => ['Expansion']],
        ];
        $query = new ServerListQuery();

        self::assertSame(['Alpha'], array_column($query->apply($servers, 'livonia'), 'name'));
        self::assertSame(['Beta'], array_column($query->apply($servers, 'expansion'), 'name'));
        self::assertSame(['Beta'], array_column($query->apply($servers, '', 'bet'), 'name'));
    }

    public function testLimitAndStats(): void
    {
        $servers = array_map(static fn (int $i): array => ['name' => "S{$i}", 'players' => $i, 'online' => true], range(1, 10));
        $query = new ServerListQuery();

        $limited = $query->apply($servers, limit: 3);

        self::assertSame([10, 9, 8], array_column($limited, 'players'));
        self::assertSame(['totalServers' => 3, 'onlineServers' => 3, 'totalPlayers' => 27, 'avgPlayers' => 9], $query->stats($limited));
    }

    public function testEmptyServersAreHiddenAfterThirtyMinutes(): void
    {
        $tracker = new ZeroPlayerTracker();
        $empty = ['ip' => '1.1.1.1', 'queryPort' => 2302, 'players' => 0];
        $busy = ['ip' => '2.2.2.2', 'queryPort' => 2302, 'players' => 7];

        $state = $tracker->update([$empty, $busy], [], 1000);

        self::assertCount(2, $tracker->filterVisible([$empty, $busy], $state, 1000 + 60));
        self::assertSame([$busy], $tracker->filterVisible([$empty, $busy], $state, 1000 + ZeroPlayerTracker::HIDE_AFTER_SECONDS));
    }
}

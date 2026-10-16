<?php

namespace App\Tests\ServerList;

use App\ServerList\ServerPopulationHistory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ServerPopulationHistoryTest extends TestCase
{
    private string $directory;
    private ServerPopulationHistory $history;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dayz-history-' . bin2hex(random_bytes(8));
        $this->history = new ServerPopulationHistory($this->directory);
    }

    protected function tearDown(): void
    {
        unset($this->history);
        (new Filesystem())->remove($this->directory);
    }

    private function server(int $players = 20, bool $online = true): array
    {
        return ['ip' => '1.2.3.4', 'queryPort' => 2302, 'players' => $players, 'online' => $online, 'maxPlayers' => 40];
    }

    public function testSameQuarterHourIsNotCountedTwiceAndMissingHoursAreNull(): void
    {
        $now = 1800000000;
        $hour = intdiv($now, 3600) * 3600;
        $this->history->record([$this->server()], $hour);
        $this->history->record([$this->server(40)], $hour + 60);
        $points = $this->history->series('1.2.3.4:2302', 1, $hour);
        self::assertCount(24, $points);
        self::assertNull($points[0]['players']);
        self::assertEquals(20, $points[23]['players']);
        self::assertSame(1, $points[23]['samples']);
        $this->history->record([$this->server()], $hour + 3600);
        self::assertFalse($this->history->summary()['servers']['1.2.3.4:2302']['ready']);
    }

    public function testWeekMetricsUseMoscowTimeAndDistinguishActiveFromAvailable(): void
    {
        $start = (new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        for ($i = 0; $i <= 672; $i++) {
            $time = $start + $i * 900;
            $hour = (int) (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('G');
            $players = $hour < 6 ? 10 : ($hour >= 10 && $hour < 18 ? 30 : 0);
            $this->history->record([$this->server($players)], $time);
        }
        $metric = $this->history->summary()['servers']['1.2.3.4:2302'];
        self::assertTrue($metric['ready']);
        self::assertEquals(100, $metric['coverage']);
        self::assertEquals(10, $metric['night']);
        self::assertEquals(30, $metric['day']);
        self::assertEquals(100, $metric['onlinePercent']);
        self::assertEquals(58.3, $metric['activePercent']);
    }

    public function testRatingWorksBeforeAFullWeekOfHistory(): void
    {
        $start = (new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        // Три дня наблюдений: меньше недели, но достаточно для рейтинга.
        for ($i = 0; $i <= 3 * 96; $i++) {
            $time = $start + $i * 900;
            $hour = (int) (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('G');
            $this->history->record([$this->server($hour < 6 ? 12 : ($hour >= 10 && $hour < 18 ? 4 : 0))], $time);
        }
        $summary = $this->history->summary();
        $metric = $summary['servers']['1.2.3.4:2302'];
        self::assertTrue($metric['ready']);
        self::assertEquals(12, $metric['night']);
        self::assertEquals(4, $metric['day']);
        self::assertEquals(3, $summary['periodDays']);
    }

    public function testOneDayOfHistoryIsNotEnough(): void
    {
        $start = 1800000000;
        for ($i = 0; $i <= 96; $i++) {
            $this->history->record([$this->server()], $start + $i * 900);
        }
        $metric = $this->history->summary()['servers']['1.2.3.4:2302'];
        self::assertFalse($metric['ready']);
        self::assertNull($metric['day']);
        self::assertEquals(20, $metric['dayAverage']);
        self::assertEquals(20, $metric['nightAverage']);
    }

    public function testObservedAveragesDoNotRequireRankingCoverageOrInventMissingPeriods(): void
    {
        $start = (new \DateTimeImmutable('2026-10-01 01:00:00', new \DateTimeZone('Europe/Moscow')))->getTimestamp();
        $this->history->record([$this->server(0)], $start);
        $this->history->record([$this->server(10)], $start + 900);
        // Пересчёт без добавления замеров: завершённый ночной час, дня ещё не было.
        $this->history->rebuildSummary($start + 3600);
        $metric = $this->history->summary()['servers']['1.2.3.4:2302'];
        self::assertFalse($metric['ready']);
        self::assertNull($metric['night']);
        self::assertEquals(5, $metric['nightAverage']);
        self::assertNull($metric['dayAverage']);
        $this->history->record([$this->server(20)], $start + 86400);
        $this->history->record([$this->server(30)], $start + 86400 + 900);
        $this->history->rebuildSummary($start + 86400 + 3600);
        self::assertEquals(15, $this->history->summary()['servers']['1.2.3.4:2302']['nightAverage']);
    }

    public function testSparseObservationsNeverQualifyAndOfflineIsZero(): void
    {
        $start = 1800000000;
        $this->history->record([$this->server(40, false)], $start);
        $this->history->record([$this->server()], $start + 7 * 86400);
        $metric = $this->history->summary()['servers']['1.2.3.4:2302'];
        self::assertFalse($metric['ready']);
        self::assertNull($metric['night']);
        self::assertEquals(0, $metric['average']);
    }
}

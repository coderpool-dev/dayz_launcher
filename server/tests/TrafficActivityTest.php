<?php

namespace App\Tests;

use App\Entity\AdminUser;
use App\Entity\TrafficEvent;
use App\Traffic\TrafficReport;
use Symfony\Component\Clock\MockClock;

final class TrafficActivityTest extends DatabaseWebTestCase
{
    public function testTodayGroupsConfirmedVisitsByHourAndSource(): void
    {
        $this->event('2026-10-18 09:10:00', 'google');
        $this->event('2026-10-18 09:45:00', 'yandex');
        $this->event('2026-10-18 12:05:00', 'direct');
        $this->event('2026-10-18 10:00:00', 'google', TrafficEvent::LEGACY);
        $this->event('2026-10-18 10:00:00', 'google', TrafficEvent::PENDING);
        $this->event('2026-10-17 23:59:59', 'google');
        $report = $this->report(1);
        self::assertSame('hour', $report['granularity']);
        self::assertCount(24, $report['series']);
        self::assertSame(3, array_sum(array_column($report['series'], 'visits')));
        self::assertSame('09:00', $report['series'][9]['label']);
        self::assertSame(['google' => 1, 'yandex' => 1], (array) $report['series'][9]['sources']);
        self::assertSame(1, $report['series'][12]['visits']);
        self::assertFalse($report['series'][12]['future']);
        self::assertTrue($report['series'][13]['future']);
        self::assertSame(0, $report['series'][10]['visits']);
    }

    public function testWeekGroupsByDayWithoutFillingInHistoricalBotTraffic(): void
    {
        $this->event('2026-10-12 09:00:00', 'google');
        $this->event('2026-10-18 09:00:00', 'steam');
        $this->event('2026-10-18 10:00:00', 'google', TrafficEvent::LEGACY);
        $report = $this->report(7);
        self::assertSame('day', $report['granularity']);
        self::assertCount(7, $report['series']);
        self::assertSame('12.10', $report['series'][0]['label']);
        self::assertSame('18.10', $report['series'][6]['label']);
        self::assertSame(2, array_sum(array_column($report['series'], 'visits')));
        self::assertSame(['steam' => 1], (array) $report['series'][6]['sources']);
    }

    public function testDashboardDefaultsToHourlyActivityWithoutRemovedExplanations(): void
    {
        $admin = new AdminUser('activity-admin');
        $this->persist($admin);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#traffic h3', 'Активность сайта');
        self::assertSelectorExists('script[src*="admin/traffic-chart.js"]');
        self::assertSelectorNotExists('.traffic-unverified');
        self::assertSelectorNotExists('#trafficChart');
        $text = $crawler->filter('#traffic')->text();
        foreach (['В основной статистике', 'Новый учёт', 'Старые запросы', 'Не входят в статистику', 'Без источника ≠'] as $removed) {
            self::assertStringNotContainsString($removed, $text);
        }
        $report = json_decode($crawler->filter('[data-traffic-data]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('hour', $report['granularity']);
        self::assertCount(24, $report['series']);
    }

    private function event(string $time, string $source, string $verification = TrafficEvent::CONFIRMED): void
    {
        $event = new TrafficEvent(TrafficEvent::VISIT, $source, null, null, '/', null, null, '192.0.2.1', new \DateTimeImmutable($time));
        $event->identify($verification, str_repeat('a', 32), null, 'Mozilla/5.0');
        $this->persist($event);
    }

    private function report(int $days): array
    {
        return (new TrafficReport($this->em->getConnection(), new MockClock('2026-10-18 12:30:00 Europe/Moscow')))->build($days);
    }
}

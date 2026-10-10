<?php

namespace App\Tests\Controller;

use App\Entity\LauncherRelease;
use App\ServerList\ServerListStore;
use App\Tests\DatabaseWebTestCase;

final class LandingTest extends DatabaseWebTestCase
{
    public function testMonitoringIsLinkedAndLoadsTheServerBrowser(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorExists('.topnav a[href="/monitoring"]');

        $this->client->request('GET', '/monitoring?q=Namalsk');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Мониторинг');
        self::assertSelectorExists('.topnav a[aria-current="page"][href="/monitoring"]');
        self::assertSelectorExists('[data-monitor][data-api="/api/monitoring"]');
        self::assertSelectorExists('select[name="mode"]');
        self::assertSelectorExists('input[name="collection"][value="night"]');
        self::assertSelectorExists('[data-history-dialog]');
        self::assertSelectorExists('input[type="search"][name="q"]');
        self::assertSelectorExists('select[name="map"]');
        self::assertSelectorExists('script[src*="/landing/monitoring.js"]');
        self::assertSelectorNotExists('.hero');
    }

    public function testLandingWithoutReleaseShowsComingSoon(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Все серверы DayZ');
        self::assertSelectorTextContains('.hero', 'Скоро будет доступен');
        self::assertSelectorTextContains('#download', 'Скоро будет доступен');
        self::assertSelectorNotExists('a[href="/download"]');
        self::assertSelectorNotExists('.now');
    }

    public function testLandingShowsDownloadButtonAndLiveStats(): void
    {
        $release = new LauncherRelease();
        $release->setVersion('1.2.0');
        $release->attachFile('PREPISKA-DayZ-Launcher-Setup-1.2.0.exe', 3 * 1048576, str_repeat('a', 64));
        $this->persist($release);
        static::getContainer()->get(ServerListStore::class)->writeStats(4123, 3702, 31337, time(), [
            ['name' => 'Rearmed US Main', 'map' => 'Chernarus', 'players' => 121, 'maxPlayers' => 121, 'mods' => 20],
        ]);

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.hero a.button-primary[href="/download"]');
        self::assertSelectorTextContains('.hero-meta', 'Версия 1.2.0');
        self::assertSelectorTextContains('.now-number', '3 702');
        self::assertSelectorTextContains('.live-stats', 'сервера с модами в каталоге DZSA');
        self::assertSelectorTextContains('.live-stats', 'Всего в каталоге 4 123 сервера');
        self::assertSelectorTextContains('.live-stats', 'играют 31 337 человек');
        self::assertSelectorTextContains('.top', 'Rearmed US Main');
        self::assertSelectorTextContains('.top', '121/121');
        self::assertSelectorTextContains('#compare', 'сверху спонсоры с пометкой «Реклама»');
        self::assertSelectorExists('#download a.button-primary[href="/download"]');
        self::assertSelectorTextContains('.cta-meta', 'Версия 1.2.0 от');
        self::assertSelectorTextContains('.cta-meta', "3,0\u{a0}МБ");
        self::assertSelectorTextContains('.checksum', str_repeat('a', 64));
    }

    /** Склонения в живой строке: 1 → «сервер», 2–4 → «сервера», 11–14 и 5–0 → «серверов». */
    public function testLiveStatsAgreeWithNumbers(): void
    {
        static::getContainer()->get(ServerListStore::class)->writeStats(4111, 3021, 21, time());

        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.live-stats', 'сервер с модами');
        self::assertSelectorTextContains('.live-stats', 'Всего в каталоге 4 111 серверов');
        self::assertSelectorTextContains('.live-stats', 'играет 21 человек');
        self::assertSelectorNotExists('.top');
    }
}

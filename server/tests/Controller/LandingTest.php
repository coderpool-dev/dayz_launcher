<?php

namespace App\Tests\Controller;

use App\Entity\LauncherRelease;
use App\ServerList\ServerListStore;
use App\Tests\DatabaseWebTestCase;

final class LandingTest extends DatabaseWebTestCase
{
    public function testLandingWithoutReleaseShowsComingSoon(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'серверы DayZ с модами');
        self::assertSelectorTextContains('.hero', 'Скоро будет доступен');
        self::assertSelectorNotExists('a[href="/download"]');
    }

    public function testLandingShowsDownloadButtonAndLiveStats(): void
    {
        $release = new LauncherRelease();
        $release->setVersion('1.2.0');
        $release->attachFile('PREPISKA-DayZ-Launcher-Setup-1.2.0.exe', 3 * 1048576, str_repeat('a', 64));
        $this->persist($release);
        static::getContainer()->get(ServerListStore::class)->writeStats(4123, 31337, time());

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.hero a.button-primary[href="/download"]');
        self::assertSelectorTextContains('.hero-meta', 'Версия 1.2.0');
        self::assertSelectorTextContains('.live-stats', '4 123');
        self::assertSelectorTextContains('.live-stats', '31 337');
    }
}

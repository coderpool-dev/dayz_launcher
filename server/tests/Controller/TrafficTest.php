<?php

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\Entity\LauncherInstall;
use App\Entity\LauncherRelease;
use App\Entity\TrafficEvent;
use App\Release\ReleaseStorage;
use App\Tests\DatabaseWebTestCase;
use App\Traffic\TrafficSource;
use Symfony\Component\Filesystem\Filesystem;

final class TrafficTest extends DatabaseWebTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    public function testClassifiesReferrers(): void
    {
        self::assertSame('google', TrafficSource::classify('https://www.google.com/'));
        self::assertSame('steam', TrafficSource::classify('https://steamcommunity.com/linkfilter/'));
        self::assertSame(TrafficSource::INTERNAL, TrafficSource::classify('https://prepiskadayz.ru/monitoring'));
        self::assertSame(TrafficSource::INTERNAL, TrafficSource::classify('https://dayz.sonetcord.ru/'));
        self::assertSame('sonetcord', TrafficSource::classify('https://sonetcord.ru/'));
        self::assertSame(TrafficSource::DIRECT, TrafficSource::classify(null));
        self::assertSame('vk', TrafficSource::classify(null, 'vk'));
    }

    public function testExternalVisitIsRecordedOnceAndFirstTouchGoesToDownload(): void
    {
        $this->client->request('GET', '/dayz-qe?utm_source=steam&utm_campaign=topic', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_REFERER' => 'https://steamcommunity.com/']);
        self::assertResponseIsSuccessful();
        // Переход внутри сайта в том же визите — не новый визит.
        $this->client->request('GET', '/monitoring', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_REFERER' => 'https://prepiskadayz.ru/dayz-qe']);
        // Робот не считается.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; YandexBot/3.0)']);

        $visits = $this->em->getRepository(TrafficEvent::class)->findBy(['type' => TrafficEvent::VISIT]);
        self::assertCount(1, $visits);
        self::assertSame('steam', $visits[0]->getSource());
        self::assertSame('/dayz-qe', $visits[0]->getLandingPath());
        self::assertSame('topic', $visits[0]->getUtmCampaign());
    }

    public function testDownloadUsesFirstTouchCookieAndSkipsLauncherUpdates(): void
    {
        $this->publishRelease();
        $this->client->request('GET', '/', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_REFERER' => 'https://yandex.ru/search/?text=dayz+launcher']);
        $this->client->request('GET', '/download/1.0.0', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_REFERER' => 'https://prepiskadayz.ru/']);
        self::assertResponseIsSuccessful();
        // Обновление из лаунчера: без браузерного User-Agent.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/download/1.0.0', server: ['HTTP_USER_AGENT' => '']);

        $downloads = $this->em->getRepository(TrafficEvent::class)->findBy(['type' => TrafficEvent::DOWNLOAD]);
        self::assertCount(1, $downloads);
        self::assertSame('yandex', $downloads[0]->getSource());
        self::assertSame('/', $downloads[0]->getLandingPath());
    }

    public function testDashboardShowsSourcesAndAttributesInstallsByIp(): void
    {
        $this->persist(
            new TrafficEvent(TrafficEvent::VISIT, 'steam', 'https://steamcommunity.com/', 'steamcommunity.com', '/', null, null, '10.0.0.5', new \DateTimeImmutable('-3 hours')),
            new TrafficEvent(TrafficEvent::DOWNLOAD, 'steam', 'https://steamcommunity.com/', 'steamcommunity.com', '/', null, null, '10.0.0.5', new \DateTimeImmutable('-2 hours')),
        );
        $install = new LauncherInstall('11111111-1111-1111-1111-111111111111', '10.0.0.5', '1.0.0', new \DateTimeImmutable('-1 hour'));
        $this->persist($install);

        $admin = new AdminUser('admin');
        $admin->setPassword('x');
        $this->persist($admin);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin?days=7');
        self::assertResponseIsSuccessful();

        $row = $crawler->filter('#traffic table tr')->reduce(static fn ($tr) => str_contains($tr->text(), 'Steam'))->first();
        self::assertSame(['Steam', '1', '1', '1'], array_slice($row->filter('td')->each(static fn ($td) => trim($td->text())), 0, 4));
    }

    private function publishRelease(): void
    {
        $storage = static::getContainer()->get(ReleaseStorage::class);
        (new Filesystem())->dumpFile($storage->path('PrepiskaLauncher-1.0.0.exe'), 'exe');
        $release = new LauncherRelease();
        $release->setVersion('1.0.0');
        $release->attachFile('PrepiskaLauncher-1.0.0.exe', 3, str_repeat('a', 64));
        $release->setPublished(true);
        $this->persist($release);
    }
}

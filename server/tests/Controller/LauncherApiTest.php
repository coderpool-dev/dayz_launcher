<?php

namespace App\Tests\Controller;

use App\Entity\DailyActivity;
use App\Entity\LauncherInstall;
use App\Entity\LauncherRelease;
use App\Entity\PlayEvent;
use App\Release\ReleaseStorage;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LauncherApiTest extends DatabaseWebTestCase
{
    private const LAUNCHER_ID = '3f2b7c1e-8a4d-4e5f-9b6a-0c1d2e3f4a5b';

    public function testStartAndHeartbeatRegisterInstallAndDailyActivity(): void
    {
        $this->sendEvent(['event' => 'start', 'version' => '1.1.0']);
        $this->sendEvent(['event' => 'heartbeat', 'version' => '1.1.0']);

        $install = $this->em->find(LauncherInstall::class, self::LAUNCHER_ID);
        self::assertNotNull($install);
        self::assertSame(1, $install->getStartCount());
        self::assertSame('1.1.0', $install->getVersion());
        self::assertSame('127.0.0.1', $install->getLastIp());
        self::assertSame(1, $this->em->getRepository(DailyActivity::class)->count([]), 'Одна запись на установку, IP и день.');
    }

    public function testPlayEventIsStored(): void
    {
        $this->sendEvent(['event' => 'play', 'version' => '1.1.0', 'serverId' => '1752401238', 'serverName' => 'PODPIVAS #11', 'serverAddress' => '185.207.214.122:2402']);

        $events = $this->em->getRepository(PlayEvent::class)->findAll();
        self::assertCount(1, $events);
        self::assertSame('PODPIVAS #11', $events[0]->getServerName());
        self::assertSame('185.207.214.122:2402', $events[0]->getServerAddress());
        self::assertSame(1, $this->em->find(LauncherInstall::class, self::LAUNCHER_ID)->getPlayCount());
    }

    public function testInvalidEventsAreRejected(): void
    {
        $this->sendEvent(['event' => 'hack'], expectOk: false);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/api/launcher/events', server: ['HTTP_X_LAUNCHER_GUID' => 'not-a-guid'], content: json_encode(['event' => 'start']));
        self::assertResponseStatusCodeSame(400);
    }

    public function testUpdateOffersLatestPublishedRelease(): void
    {
        $this->createRelease('1.1.0', published: true);
        $this->createRelease('1.2.0', published: true, notes: 'Автообновление');
        $this->createRelease('1.3.0', published: false);

        $this->client->request('GET', '/api/launcher/update', ['version' => '1.1.0']);
        $json = $this->jsonResponse();
        self::assertTrue($json['updateAvailable']);
        self::assertSame('1.2.0', $json['latestVersion']);
        self::assertSame('Автообновление', $json['notes']);
        self::assertStringEndsWith('/download/1.2.0', $json['url']);
        self::assertSame(hash('sha256', 'installer 1.2.0'), $json['sha256']);

        $this->client->request('GET', '/api/launcher/update', ['version' => '1.2.0']);
        self::assertFalse($this->jsonResponse()['updateAvailable']);
    }

    public function testDownloadLatestInstaller(): void
    {
        $this->createRelease('1.2.0', published: true);

        $this->client->request('GET', '/download');
        self::assertResponseRedirects('/download/1.2.0');

        $this->client->request('GET', '/download/1.2.0');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Content-SHA256', hash('sha256', 'installer 1.2.0'));

        $this->client->request('GET', '/download/9.9.9');
        self::assertResponseStatusCodeSame(404);
    }

    private function sendEvent(array $payload, bool $expectOk = true): void
    {
        $this->client->request('POST', '/api/launcher/events', server: ['HTTP_X_LAUNCHER_GUID' => self::LAUNCHER_ID, 'CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
        if ($expectOk) {
            self::assertResponseIsSuccessful();
        }
    }

    private function createRelease(string $version, bool $published, ?string $notes = null): void
    {
        $storage = static::getContainer()->get(ReleaseStorage::class);
        $fileName = "PREPISKA-DayZ-Launcher-Setup-{$version}.exe";
        (new Filesystem())->dumpFile($storage->path($fileName), "installer {$version}");

        $release = new LauncherRelease();
        $release->setVersion($version);
        $release->setNotes($notes);
        $release->setPublished($published);
        $release->attachFile($fileName, strlen("installer {$version}"), hash('sha256', "installer {$version}"));
        $this->persist($release);
    }
}

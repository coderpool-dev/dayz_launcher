<?php

namespace App\Tests\Controller;

use App\Entity\TrafficEvent;
use App\Entity\LauncherRelease;
use App\Release\ReleaseStorage;
use App\Tests\DatabaseWebTestCase;
use App\Traffic\BrowserTraffic;
use App\Traffic\TrafficRecorder;
use App\Traffic\TrafficReport;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmedTrafficTest extends DatabaseWebTestCase
{
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/150.0.0.0 Safari/537.36';

    public function testPageFetchIsNotAConfirmedVisitAndCookiesArePrivate(): void
    {
        $this->visit('/?utm_source=google');
        self::assertSelectorExists('script[src*="landing/traffic.js"]');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertSame(0, $this->report()['totals']['visits']);
        self::assertSame(1, $this->report()['totals']['pending']);
        self::assertTrue($this->client->getCookieJar()->get(BrowserTraffic::VISITOR_COOKIE)->isHttpOnly());
    }

    public function testCookieAndMinimumDurationAreRequiredAndConfirmationIsIdempotent(): void
    {
        $this->confirm();
        self::assertFalse($this->jsonResponse()['confirmed']);
        $this->visit('/dayz-qe?utm_source=steam');
        $this->confirm();
        self::assertFalse($this->jsonResponse()['confirmed']);
        $this->ageVisit();
        $this->confirm();
        self::assertTrue($this->jsonResponse()['confirmed']);
        $this->confirm();
        self::assertTrue($this->jsonResponse()['confirmed']);
        $report = $this->report();
        self::assertSame(1, $report['totals']['visits']);
        self::assertSame(1, $report['totals']['visitors']);
        self::assertSame('steam', $report['sources'][0]['source']);
        self::assertSame(0, $report['totals']['pending']);
    }

    public function testKnownBotsAndPrefetchNeverCreateVisits(): void
    {
        foreach (['Mozilla/5.0 (compatible; GoogleOther)', 'Mozilla/5.0 (compatible; CMS-Checker/1.0)', 'Mozilla/5.0 stratagy-scanner/1.0', 'Mozilla/5.0 HeadlessChrome/150', 'Mozilla/5.0 YandexBot/3.0'] as $agent) {
            $this->client->request('GET', '/', server: ['HTTP_USER_AGENT' => $agent]);
            self::assertResponseIsSuccessful();
        }
        $this->client->request('GET', '/', server: ['HTTP_USER_AGENT' => self::BROWSER, 'HTTP_SEC_PURPOSE' => 'prefetch']);
        self::assertSame(0, $this->report()['totals']['pending']);
        self::assertSame(0, $this->report()['totals']['visits']);
    }

    public function testRepeatedExternalReferrerAndNavigationDoNotInflateVisitCount(): void
    {
        $this->visit('/?utm_source=google');
        $this->visit('/?utm_source=google');
        $this->visit('/monitoring?utm_source=steam');
        self::assertSame(1, $this->report()['totals']['pending']);
        $this->ageVisit();
        $this->confirm();
        self::assertSame('google', $this->report()['sources'][0]['source']);
        self::assertSame(1, $this->report()['totals']['visits']);
    }

    public function testLegacyHistoryIsPreservedButExcludedEverywhere(): void
    {
        $this->persist(
            new TrafficEvent(TrafficEvent::VISIT, 'google', 'https://google.com/', 'google.com', '/old', 'old', 'old', '192.0.2.1'),
            new TrafficEvent(TrafficEvent::DOWNLOAD, 'google', 'https://google.com/', 'google.com', '/old', 'old', 'old', '192.0.2.1'),
        );
        $report = $this->report();
        self::assertSame(1, $report['totals']['legacy']);
        self::assertSame(0, $report['totals']['visits']);
        self::assertSame(0, $report['totals']['downloads']);
        foreach (['sources', 'referrers', 'landings', 'campaigns', 'recentDownloads'] as $section) {
            self::assertSame([], $report[$section], $section);
        }
        self::assertSame(0, array_sum(array_column($report['daily'], 'visits')));
    }

    public function testTamperedCookieChangedAgentAndCrossSiteConfirmationAreRejected(): void
    {
        $this->visit('/');
        $this->ageVisit();
        $this->confirm(['HTTP_ORIGIN' => 'https://evil.invalid']);
        self::assertFalse($this->jsonResponse()['confirmed']);
        $this->confirm(['HTTP_SEC_FETCH_SITE' => 'cross-site']);
        self::assertFalse($this->jsonResponse()['confirmed']);
        $this->confirm(['HTTP_X_TRAFFIC_EVENT' => '']);
        self::assertFalse($this->jsonResponse()['confirmed']);
        $this->confirm(['HTTP_USER_AGENT' => self::BROWSER . ' changed']);
        self::assertFalse($this->jsonResponse()['confirmed']);
        $cookie = $this->client->getCookieJar()->get(TrafficRecorder::VISIT_COOKIE);
        $this->client->getCookieJar()->set(new Cookie(TrafficRecorder::VISIT_COOKIE, $cookie->getValue() . 'x', null, '/', $cookie->getDomain()));
        $this->confirm();
        self::assertFalse($this->jsonResponse()['confirmed']);
        self::assertSame(0, $this->report()['totals']['visits']);
    }

    public function testTwoBrowsersOnSameIpAreNotMerged(): void
    {
        $this->visit('/');
        $this->ageVisit();
        $this->confirm();
        $this->client->getCookieJar()->clear();
        $this->visit('/');
        $this->ageVisit();
        $this->confirm();
        self::assertSame(2, $this->report()['totals']['visitors']);
    }

    public function testDownloadsAreConfirmedWithTheirVisitAndRetriesAreDeduplicated(): void
    {
        $storage = static::getContainer()->get(ReleaseStorage::class);
        (new \Symfony\Component\Filesystem\Filesystem())->dumpFile($storage->path('traffic-test.exe'), 'exe');
        $release = new LauncherRelease();
        $release->setVersion('1.0.0');
        $release->attachFile('traffic-test.exe', 3, str_repeat('a', 64));
        $release->setPublished(true);
        $this->persist($release);
        $this->visit('/?utm_source=yandex');
        foreach (range(1, 2) as $attempt) {
            $this->client->request('GET', '/download/1.0.0', server: ['HTTP_USER_AGENT' => self::BROWSER]);
            self::assertResponseIsSuccessful();
        }
        self::assertSame(0, $this->report()['totals']['downloads']);
        $this->ageVisit();
        $this->confirm();
        self::assertTrue($this->jsonResponse()['confirmed']);
        $report = $this->report();
        self::assertSame(1, $report['totals']['downloads']);
        self::assertSame(1, $report['totals']['convertedVisits']);
        self::assertSame('yandex', $report['sources'][0]['source']);
        self::assertSame(1, $report['sources'][0]['downloads']);
        $this->client->request('GET', '/download/1.0.0', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertSame(1, $this->report()['totals']['downloads']);
    }

    public function testSignedSessionExpiresAndReturningBrowserHasStableIdentity(): void
    {
        $db = static::getContainer()->get('doctrine')->getConnection();
        $clock = new MockClock(new \DateTimeImmutable());
        $traffic = new BrowserTraffic($db, $clock, 'test-secret');
        $request = Request::create('https://prepiskadayz.ru/', server: ['HTTP_USER_AGENT' => self::BROWSER]);
        $visitor = $traffic->visitorId($request);
        $event = new TrafficEvent(TrafficEvent::VISIT, 'direct', null, null, '/', null, null, '192.0.2.1');
        $event->identify(TrafficEvent::PENDING, $visitor, null, self::BROWSER);
        $this->persist($event);
        $response = new Response();
        $traffic->setCookies($request, $response, $event->getId(), $visitor);
        foreach ($response->headers->getCookies() as $cookie) {
            self::assertTrue($cookie->isSecure());
            $request->cookies->set($cookie->getName(), $cookie->getValue());
        }
        self::assertNotNull($traffic->currentVisit($request));
        $clock->sleep(1801);
        self::assertNull($traffic->currentVisit($request));
        self::assertSame($visitor, $traffic->visitorId($request));
    }

    private function visit(string $url): void
    {
        $this->client->request('GET', $url, server: ['HTTP_USER_AGENT' => self::BROWSER]);
        self::assertResponseIsSuccessful();
    }

    private function confirm(array $server = []): void
    {
        $this->client->request('POST', '/api/traffic/confirm', server: array_merge([
            'HTTP_USER_AGENT' => self::BROWSER, 'HTTP_X_TRAFFIC_EVENT' => 'engaged', 'HTTP_ORIGIN' => 'http://localhost',
        ], $server));
        self::assertResponseIsSuccessful();
    }

    private function ageVisit(): void
    {
        static::getContainer()->get('doctrine')->getConnection()->executeStatement("UPDATE traffic_event SET created_at = datetime(created_at, '-10 seconds') WHERE type = 'visit'");
    }

    private function report(): array
    {
        return static::getContainer()->get(TrafficReport::class)->build(30);
    }
}

<?php

namespace App\Tests\Controller;

use App\Entity\LauncherRelease;
use App\Tests\DatabaseWebTestCase;

final class SeoTest extends DatabaseWebTestCase
{
    public function testHomepageHasCanonicalMetadataAndHonestApplicationData(): void
    {
        $crawler = $this->client->request('GET', '/?utm_source=test');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'DayZ Launcher PREPISKA');
        self::assertSelectorExists('link[rel="canonical"][href="https://dayz.sonetcord.ru/"]');
        self::assertSelectorExists('meta[property="og:url"][content="https://dayz.sonetcord.ru/"]');
        $schema = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('WebSite', $schema['@graph'][0]['@type']);
        $app = $schema['@graph'][1];
        self::assertSame('SoftwareApplication', $app['@type']);
        self::assertSame('PREPISKA DayZ Launcher', $app['name']);
        self::assertArrayNotHasKey('aggregateRating', $app);
        self::assertArrayNotHasKey('downloadUrl', $app);
        self::assertArrayNotHasKey('softwareVersion', $app);
    }

    public function testApplicationDataUsesTheActualPublishedRelease(): void
    {
        $release = new LauncherRelease();
        $release->setVersion('1.2.0');
        $release->attachFile('launcher.exe', 1024, str_repeat('a', 64));
        $this->persist($release);
        $crawler = $this->client->request('GET', '/');
        $schema = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('1.2.0', $schema['@graph'][1]['softwareVersion']);
        self::assertSame('https://dayz.sonetcord.ru/download/1.2.0', $schema['@graph'][1]['downloadUrl']);
        self::assertSame('0', $schema['@graph'][1]['offers']['price']);
    }

    public function testMonitoringHasItsOwnMetadataWithoutQueryDuplicates(): void
    {
        $crawler = $this->client->request('GET', '/monitoring?q=Namalsk&map=namalsk');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('link[rel="canonical"][href="https://dayz.sonetcord.ru/monitoring"]');
        self::assertStringContainsString('Найдите сервер DayZ', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame($crawler->filter('title')->text(), $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertSelectorNotExists('script[type="application/ld+json"]');
    }

    public function testRobotsAndSitemapExposeOnlyPublicPages(): void
    {
        $this->client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/plain; charset=UTF-8');
        self::assertStringContainsString('Sitemap: https://dayz.sonetcord.ru/sitemap.xml', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Disallow: /admin', $this->client->getResponse()->getContent());
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        $xml = simplexml_load_string($this->client->getResponse()->getContent());
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        self::assertSame(['https://dayz.sonetcord.ru/', 'https://dayz.sonetcord.ru/monitoring'], array_map('strval', $xml->xpath('//s:loc')));
    }
}

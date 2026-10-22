<?php

namespace App\Tests\Controller;

use App\Entity\AdminUser;
use App\ServerList\ServerListPublisher;
use App\ServerList\ServerListSnapshot;
use App\Tests\DatabaseWebTestCase;

final class PublicLocaleTest extends DatabaseWebTestCase
{
    public function testLanguageNegotiationAndManualPreference(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru-RU,ru;q=0.9,en;q=0.8']);
        self::assertSelectorExists('html[lang="ru"]');
        self::assertSelectorTextContains('.hero-status', 'Работает в России без VPN.');
        self::assertSelectorTextContains('#download', 'Бесплатный дейз лаунчер');

        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        self::assertSelectorExists('html[lang="en"]');
        self::assertResponseHeaderSame('Content-Language', 'en');
        self::assertSelectorTextContains('.hero .lead', 'Servers, mods and DayZ');
        self::assertSelectorTextContains('#download', 'A free DayZ launcher');
        self::assertSelectorExists('link[rel="canonical"][href$="?lang=en"]');
        self::assertContains('Accept-Language', $this->client->getResponse()->getVary());
        self::assertContains('Cookie', $this->client->getResponse()->getVary());

        $this->client->request('GET', '/monitoring?q=Namalsk&lang=ru', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        self::assertSelectorExists('html[lang="ru"]');
        self::assertSelectorExists('.language-switch a[href="/monitoring?q=Namalsk&lang=en"]');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertSame('ru', $this->client->getCookieJar()->get('site_language')->getValue());

        $this->client->request('GET', '/dayz-qe', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US']);
        self::assertSelectorExists('html[lang="ru"]');
        $this->client->request('GET', '/dayz-qe?lang=en', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);
        self::assertSelectorExists('html[lang="en"]');
        self::assertSame('en', $this->client->getCookieJar()->get('site_language')->getValue());
    }

    public function testAllPublicPagesHaveEnglishUiWithoutTranslatingServerContent(): void
    {
        static::getContainer()->get(ServerListPublisher::class)->publish(new ServerListSnapshot([
            ['name' => 'Мир PvE', 'ip' => '192.0.2.1', 'queryPort' => 2302, 'gamePort' => 2300,
                'players' => 2, 'maxPlayers' => 60, 'online' => true, 'map' => 'namalsk', 'mapName' => 'Namalsk'],
        ], [], [], time(), 'refreshed'));

        foreach (['/', '/monitoring', '/dayz-qe', '/dzsa-launcher-ne-rabotaet', '/krasnyy-launcher-dayz', '/monitoring/map/namalsk', '/monitoring/pve', '/monitoring/server/192.0.2.1-2302', '/monitoring/server/192.0.2.2-2302'] as $url) {
            $crawler = $this->client->request('GET', $url, server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);
            self::assertResponseStatusCodeSame(str_contains($url, '192.0.2.2') ? 404 : 200);
            self::assertSelectorExists('html[lang="en"]');
            self::assertDoesNotMatchRegularExpression('/[а-яё]/ui', str_replace('Мир PvE', '', $crawler->filter('body')->text()), $url);
            foreach ($crawler->filter('script[type="application/ld+json"]') as $schema) {
                json_decode($schema->textContent, true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $this->client->request('GET', '/monitoring', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertSelectorTextContains('[data-servers]', 'Мир PvE');
        self::assertSelectorExists('script[src*="monitoring-i18n.js"]');
    }

    public function testInvalidLanguageAndMissingHeaderUseSafeDefaults(): void
    {
        $this->client->request('GET', '/?lang[]=en', server: ['HTTP_ACCEPT_LANGUAGE' => 'ru']);
        self::assertSelectorExists('html[lang="ru"]');
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', '');
        $this->client->request('GET', '/?lang=invalid');
        self::assertSelectorExists('html[lang="ru"]');
    }

    public function testEnglishPreferenceDoesNotChangeAdminAndDashboardIsResponsive(): void
    {
        $this->persist(new AdminUser('mobile-admin'));
        $this->client->loginUser($this->em->getRepository(AdminUser::class)->findOneBy(['username' => 'mobile-admin']));
        $this->client->request('GET', '/admin?lang=en', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Статистика лаунчера');
        self::assertSelectorExists('link[href*="admin/mobile.css"]');
        self::assertSelectorExists('link[href*="admin/dashboard.css"]');
        self::assertSelectorExists('[data-traffic-activity] [data-traffic-bars]');
        self::assertSelectorNotExists('.panel-grid[style*="grid-template-columns"]');
        self::assertSelectorExists('meta[name="viewport"][content="width=device-width, initial-scale=1"]');
    }
}

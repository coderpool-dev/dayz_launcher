<?php

namespace App\Controller;

use App\Seo\SeoUrls;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController
{
    public function __construct(
        private readonly SeoUrls $urls,
        #[Autowire('%env(INDEXNOW_KEY)%')] private readonly string $indexNowKey,
    ) {
    }

    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(): Response
    {
        return new Response("User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api/\nSitemap: https://prepiskadayz.ru/sitemap.xml\n", headers: [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    #[Route('/sitemap.xml', name: 'seo_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($this->urls->all() as $url) {
            $xml .= '    <url><loc>' . htmlspecialchars($url['loc'], ENT_XML1) . '</loc>'
                . ($url['lastmod'] ? '<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $url['lastmod']) . '</lastmod>' : '') . "</url>\n";
        }

        return new Response($xml . '</urlset>', headers: [
            'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=900',
        ]);
    }

    /** Ключ IndexNow: Яндекс проверяет по нему, что список адресов прислал владелец сайта. */
    #[Route('/indexnow.txt', name: 'seo_indexnow_key', methods: ['GET'])]
    public function indexNowKey(): Response
    {
        return new Response($this->indexNowKey, headers: ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}

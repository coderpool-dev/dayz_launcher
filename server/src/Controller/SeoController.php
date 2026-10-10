<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController
{
    #[Route('/robots.txt', name: 'seo_robots', methods: ['GET'])]
    public function robots(): Response
    {
        return new Response("User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /api/\nSitemap: https://dayz.sonetcord.ru/sitemap.xml\n", headers: [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    #[Route('/sitemap.xml', name: 'seo_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        return new Response('<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://dayz.sonetcord.ru/</loc></url>
    <url><loc>https://dayz.sonetcord.ru/monitoring</loc></url>
</urlset>', headers: [
            'Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}

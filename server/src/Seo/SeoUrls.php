<?php

namespace App\Seo;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Адреса страниц, открытых для индексации: для sitemap.xml и отправки в IndexNow. */
final class SeoUrls
{
    public const HOST = 'https://prepiskadayz.ru';

    public function __construct(
        private readonly SeoIndex $index,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** @return list<array{loc: string, lastmod: int|null}> */
    public function all(): array
    {
        $updatedAt = $this->index->read()['updatedAt'] ?: null;
        $list = [
            ['loc' => self::HOST . $this->urls->generate('landing'), 'lastmod' => null],
            ['loc' => self::HOST . $this->urls->generate('monitoring'), 'lastmod' => $updatedAt],
            ['loc' => self::HOST . $this->urls->generate('guide_qe'), 'lastmod' => null],
            ['loc' => self::HOST . $this->urls->generate('guide_dzsa'), 'lastmod' => null],
            ['loc' => self::HOST . $this->urls->generate('guide_red_launcher'), 'lastmod' => null],
        ];
        foreach (array_keys(MonitoringPages::modes()) as $mode) {
            $list[] = ['loc' => self::HOST . $this->urls->generate('monitoring_mode', ['mode' => $mode]), 'lastmod' => $updatedAt];
        }
        foreach ($this->index->maps() as $map) {
            if ($map['servers'] >= MonitoringPages::MAP_MIN_SERVERS) {
                $list[] = ['loc' => self::HOST . $this->urls->generate('monitoring_map', ['map' => $map['slug']]), 'lastmod' => $updatedAt];
            }
        }
        foreach ($this->index->read()['servers'] as $server) {
            if (SeoIndex::isIndexable($server)) {
                $list[] = ['loc' => self::HOST . $this->urls->generate('monitoring_server', ['slug' => $server['slug']]), 'lastmod' => $updatedAt];
            }
        }

        return $list;
    }
}

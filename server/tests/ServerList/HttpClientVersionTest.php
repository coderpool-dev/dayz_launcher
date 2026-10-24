<?php

namespace App\Tests\ServerList;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Список серверов DZSA скачивается общим HTTP-клиентом приложения. По HTTP/2 он застревал на
 * больших ответах (12 МБ не дочитывались за 25 с) — клиент по умолчанию работает по HTTP/1.1.
 */
final class HttpClientVersionTest extends KernelTestCase
{
    public function testUpstreamDownloadsUseHttp11(): void
    {
        $client = static::getContainer()->get(HttpClientInterface::class);

        self::assertSame('1.1', $this->defaultOptions($client)['http_version'] ?? null);
    }

    /** Опции по умолчанию самого клиента, под обёртками Symfony (трассировка, ретраи, scoping). */
    private function defaultOptions(object $client): array
    {
        for ($depth = 0; $depth < 10; ++$depth) {
            $reflection = new \ReflectionObject($client);
            while ($reflection !== false) {
                if ($reflection->hasProperty('defaultOptions')) {
                    $property = $reflection->getProperty('defaultOptions');

                    return $property->getValue($client);
                }
                $reflection = $reflection->getParentClass();
            }
            $reflection = new \ReflectionObject($client);
            if (!$reflection->hasProperty('client')) {
                break;
            }
            $client = $reflection->getProperty('client')->getValue($client);
        }

        self::fail('Default options of the HTTP client were not found');
    }
}

<?php

namespace App\ServerList;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Подключает приватный UpstreamClient, если он есть, иначе заглушку. */
final class UpstreamSourceFactory
{
    /** Имя строкой: класса может не быть в репозитории. */
    private const PRIVATE_CLIENT = __NAMESPACE__ . '\\UpstreamClient';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(BATTLEMETRICS_TOKEN)%')]
        private readonly string $battlemetricsToken,
    ) {
    }

    public function create(): UpstreamSource
    {
        $class = self::PRIVATE_CLIENT;

        return class_exists($class) ? new $class($this->httpClient, $this->battlemetricsToken) : new NullUpstreamSource();
    }
}

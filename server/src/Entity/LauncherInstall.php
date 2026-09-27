<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Установка лаунчера (по анонимному ID из заголовка x-launcher-guid).
 * lastSeenAt обновляется при каждом событии — по нему считается онлайн.
 */
#[ORM\Entity]
#[ORM\Index(fields: ['lastSeenAt'])]
class LauncherInstall
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $launcherId;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 45)]
    private string $lastIp;

    #[ORM\Column(length: 20)]
    private string $version;

    #[ORM\Column]
    private int $startCount = 0;

    #[ORM\Column]
    private int $playCount = 0;

    public function __construct(string $launcherId, string $ip, string $version, \DateTimeImmutable $now)
    {
        $this->launcherId = $launcherId;
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
        $this->lastIp = $ip;
        $this->version = $version;
    }

    public function touch(string $ip, string $version, \DateTimeImmutable $now): void
    {
        $this->lastSeenAt = $now;
        $this->lastIp = $ip;
        $this->version = $version;
    }

    public function registerStart(): void { ++$this->startCount; }
    public function registerPlay(): void { ++$this->playCount; }

    public function getLauncherId(): string { return $this->launcherId; }
    public function getFirstSeenAt(): \DateTimeImmutable { return $this->firstSeenAt; }
    public function getLastSeenAt(): \DateTimeImmutable { return $this->lastSeenAt; }
    public function getLastIp(): string { return $this->lastIp; }
    public function getVersion(): string { return $this->version; }
    public function getStartCount(): int { return $this->startCount; }
    public function getPlayCount(): int { return $this->playCount; }
}

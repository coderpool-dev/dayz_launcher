<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Лаунчер был запущен в этот день с этого IP. Одна запись на (день, установка, IP) —
 * отсюда считается, сколько людей пользовались лаунчером в день.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['day', 'launcherId', 'ip'])]
#[ORM\Index(fields: ['day'])]
class DailyActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;

    #[ORM\Column(length: 36)]
    private string $launcherId;

    #[ORM\Column(length: 45)]
    private string $ip;

    public function __construct(\DateTimeImmutable $day, string $launcherId, string $ip)
    {
        $this->day = $day->setTime(0, 0);
        $this->launcherId = $launcherId;
        $this->ip = $ip;
    }

    public function getId(): ?int { return $this->id; }
    public function getDay(): \DateTimeImmutable { return $this->day; }
    public function getLauncherId(): string { return $this->launcherId; }
    public function getIp(): string { return $this->ip; }
}

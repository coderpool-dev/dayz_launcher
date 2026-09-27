<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Спонсорский сервер: закрепляется наверху списка в лаунчере.
 * Сервер из списка совпадает, если его IP и query- или game-порт равны указанным.
 */
#[ORM\Entity]
class SponsorServer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Заметка для админа (название проекта, контакт и т.п.). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $title = '';

    #[ORM\Column(length: 45)]
    #[Assert\NotBlank]
    #[Assert\Ip(version: Assert\Ip::ALL)]
    private string $ip = '';

    #[ORM\Column]
    #[Assert\Range(min: 1, max: 65535)]
    private int $port = 2302;

    /** Чем больше — тем выше в списке среди спонсоров. */
    #[ORM\Column]
    private int $priority = 0;

    #[ORM\Column]
    private bool $active = true;

    /** Дата окончания размещения; пусто — бессрочно. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activeUntil = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isEffective(\DateTimeImmutable $now): bool
    {
        return $this->active && ($this->activeUntil === null || $this->activeUntil > $now);
    }

    public function matches(string $ip, int ...$ports): bool
    {
        return $this->ip === $ip && in_array($this->port, $ports, true);
    }

    public function getId(): ?int { return $this->id; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(?string $title): void { $this->title = trim((string) $title); }
    public function getIp(): string { return $this->ip; }
    public function setIp(?string $ip): void { $this->ip = trim((string) $ip); }
    public function getPort(): int { return $this->port; }
    public function setPort(?int $port): void { $this->port = (int) $port; }
    public function getPriority(): int { return $this->priority; }
    public function setPriority(?int $priority): void { $this->priority = (int) $priority; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $active): void { $this->active = $active; }
    public function getActiveUntil(): ?\DateTimeImmutable { return $this->activeUntil; }
    public function setActiveUntil(?\DateTimeImmutable $activeUntil): void { $this->activeUntil = $activeUntil; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getAddress(): string
    {
        return $this->ip . ':' . $this->port;
    }

    public function __toString(): string
    {
        return $this->title . ' (' . $this->getAddress() . ')';
    }
}

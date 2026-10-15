<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Заход на сайт (visit) или скачивание установщика из браузера (download) с источником:
 * поиск, Steam, соцсети, utm-метка. У скачивания источник — первый заход этого браузера.
 */
#[ORM\Entity]
#[ORM\Index(fields: ['createdAt'])]
#[ORM\Index(fields: ['type', 'createdAt'])]
#[ORM\Index(fields: ['ip'])]
class TrafficEvent
{
    public const VISIT = 'visit';
    public const DOWNLOAD = 'download';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 10)]
        private string $type,
        #[ORM\Column(length: 32)]
        private string $source,
        #[ORM\Column(length: 1024, nullable: true)]
        private ?string $referrer,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $referrerHost,
        #[ORM\Column(length: 512)]
        private string $landingPath,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $utmSource,
        #[ORM\Column(length: 100, nullable: true)]
        private ?string $utmCampaign,
        #[ORM\Column(length: 45)]
        private string $ip,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getSource(): string { return $this->source; }
    public function getReferrer(): ?string { return $this->referrer; }
    public function getReferrerHost(): ?string { return $this->referrerHost; }
    public function getLandingPath(): string { return $this->landingPath; }
    public function getUtmSource(): ?string { return $this->utmSource; }
    public function getUtmCampaign(): ?string { return $this->utmCampaign; }
    public function getIp(): string { return $this->ip; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

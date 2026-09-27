<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Нажатие «Играть» в лаунчере.
 */
#[ORM\Entity]
#[ORM\Index(fields: ['createdAt'])]
class PlayEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 36)]
    private string $launcherId;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 20)]
    private string $version;

    #[ORM\Column(length: 20)]
    private string $serverId;

    #[ORM\Column(length: 255)]
    private string $serverName;

    #[ORM\Column(length: 64)]
    private string $serverAddress;

    public function __construct(
        \DateTimeImmutable $createdAt,
        string $launcherId,
        string $ip,
        string $version,
        string $serverId,
        string $serverName,
        string $serverAddress,
    ) {
        $this->createdAt = $createdAt;
        $this->launcherId = $launcherId;
        $this->ip = $ip;
        $this->version = $version;
        $this->serverId = $serverId;
        $this->serverName = $serverName;
        $this->serverAddress = $serverAddress;
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLauncherId(): string { return $this->launcherId; }
    public function getIp(): string { return $this->ip; }
    public function getVersion(): string { return $this->version; }
    public function getServerId(): string { return $this->serverId; }
    public function getServerName(): string { return $this->serverName; }
    public function getServerAddress(): string { return $this->serverAddress; }
}

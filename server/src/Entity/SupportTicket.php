<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Обращение в поддержку с сайта: «лаунчер не работает» и прочие вопросы. */
#[ORM\Entity]
#[ORM\Index(fields: ['status', 'createdAt'])]
class SupportTicket
{
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_RESOLVED = 'resolved';

    public const STATUSES = [
        'Новое' => self::STATUS_NEW,
        'Прочитано' => self::STATUS_READ,
        'Решено' => self::STATUS_RESOLVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Как ответить: Telegram, Discord, почта — в свободной форме. */
    #[ORM\Column(length: 200)]
    private string $contact;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $launcherVersion;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(length: 10)]
    private string $status = self::STATUS_NEW;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNote = null;

    public function __construct(string $name, string $contact, ?string $launcherVersion, string $message, string $ip, ?string $userAgent, \DateTimeImmutable $now)
    {
        $this->name = $name;
        $this->contact = $contact;
        $this->launcherVersion = $launcherVersion;
        $this->message = $message;
        $this->ip = $ip;
        $this->userAgent = $userAgent !== null ? mb_substr($userAgent, 0, 255) : null;
        $this->createdAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getName(): string { return $this->name; }
    public function getContact(): string { return $this->contact; }
    public function getLauncherVersion(): ?string { return $this->launcherVersion; }
    public function getMessage(): string { return $this->message; }
    public function getIp(): string { return $this->ip; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getStatus(): string { return $this->status; }
    public function getAdminNote(): ?string { return $this->adminNote; }

    public function setStatus(string $status): void
    {
        if (in_array($status, self::STATUSES, true)) {
            $this->status = $status;
        }
    }

    public function setAdminNote(?string $adminNote): void { $this->adminNote = $adminNote; }

    public function __toString(): string
    {
        return sprintf('#%d %s', (int) $this->id, $this->name);
    }
}

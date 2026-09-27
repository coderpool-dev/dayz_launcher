<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Версия лаунчера, загруженная через админку. Лаунчер скачивает установщик
 * последней опубликованной версии и обновляется.
 */
#[ORM\Entity]
#[UniqueEntity('version', message: 'Такая версия уже загружена.')]
class LauncherRelease
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^\d+\.\d+\.\d+$/', message: 'Версия в формате 1.2.3.')]
    private string $version = '';

    /** Что нового — показывается пользователю в лаунчере. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /** Имя файла установщика в хранилище релизов. */
    #[ORM\Column(length: 255)]
    private string $fileName = '';

    #[ORM\Column]
    private int $fileSize = 0;

    #[ORM\Column(length: 64)]
    private string $sha256 = '';

    #[ORM\Column]
    private bool $published = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** Загружаемый файл (только в форме админки, в БД не хранится). */
    #[Assert\File(maxSize: '200M', extensions: ['exe'], extensionsMessage: 'Загрузите установщик .exe.')]
    private ?UploadedFile $upload = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVersion(): string { return $this->version; }
    public function setVersion(?string $version): void { $this->version = trim((string) $version); }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): void { $this->notes = $notes !== null && trim($notes) !== '' ? trim($notes) : null; }
    public function getFileName(): string { return $this->fileName; }
    public function getFileSize(): int { return $this->fileSize; }
    public function getSha256(): string { return $this->sha256; }
    public function isPublished(): bool { return $this->published; }
    public function setPublished(bool $published): void { $this->published = $published; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpload(): ?UploadedFile { return $this->upload; }
    public function setUpload(?UploadedFile $upload): void { $this->upload = $upload; }

    public function attachFile(string $fileName, int $fileSize, string $sha256): void
    {
        $this->fileName = $fileName;
        $this->fileSize = $fileSize;
        $this->sha256 = $sha256;
        $this->upload = null;
    }

    public function hasFile(): bool
    {
        return $this->fileName !== '';
    }

    public function __toString(): string
    {
        return $this->version;
    }
}

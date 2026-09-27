<?php

namespace App\Release;

use App\Entity\LauncherRelease;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Файлы установщиков лаунчера. Хранятся вне public/ и отдаются через DownloadController.
 */
final class ReleaseStorage
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Сохраняет загруженный установщик и записывает в релиз имя файла, размер и SHA-256.
     */
    public function store(LauncherRelease $release, UploadedFile $upload): void
    {
        $previous = $release->hasFile() ? $release->getFileName() : null;
        $fileName = sprintf('PREPISKA-DayZ-Launcher-Setup-%s.exe', $release->getVersion());

        $this->filesystem->mkdir($this->directory);
        $upload->move($this->directory, $fileName);
        $path = $this->path($fileName);

        $release->attachFile($fileName, (int) filesize($path), (string) hash_file('sha256', $path));

        if ($previous !== null && $previous !== $fileName) {
            $this->filesystem->remove($this->path($previous));
        }
    }

    public function remove(LauncherRelease $release): void
    {
        if ($release->hasFile()) {
            $this->filesystem->remove($this->path($release->getFileName()));
        }
    }

    public function path(string $fileName): string
    {
        return $this->directory . '/' . basename($fileName);
    }
}

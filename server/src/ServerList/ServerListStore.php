<?php

namespace App\ServerList;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Файлы списка серверов:
 *  - servers.json         — полный снимок (все серверы, ошибки источников, meta);
 *  - servers-public.json  — готовый ответ /api/servers без параметров (его запрашивают лаунчеры);
 *  - zero-player-state.json — с какого момента серверы пустые.
 * Запись атомарная (через временный файл), поэтому читатели никогда не видят половину файла.
 */
final class ServerListStore
{
    private const SNAPSHOT = 'servers.json';
    private const PUBLIC_PAYLOAD = 'servers-public.json';
    private const ZERO_PLAYER_STATE = 'zero-player-state.json';

    private readonly Filesystem $filesystem;

    public function __construct(private readonly string $directory)
    {
        $this->filesystem = new Filesystem();
    }

    /** Возраст снимка в секундах без чтения файла; null — снимка нет. */
    public function snapshotAge(): ?int
    {
        clearstatcache(true, $this->path(self::SNAPSHOT));
        $modifiedAt = @filemtime($this->path(self::SNAPSHOT));

        return $modifiedAt === false ? null : max(0, time() - $modifiedAt);
    }

    /**
     * Полный снимок. Декодирование занимает сотни мегабайт — вызывать только при сборке ответа.
     *
     * @return array{servers: list<array>, errors: list<array>, meta: array, storedAt: int}|null
     */
    public function readSnapshot(): ?array
    {
        $data = $this->readJson(self::SNAPSHOT);

        return is_array($data['servers'] ?? null) ? $data + ['errors' => [], 'meta' => [], 'storedAt' => 0] : null;
    }

    public function writeSnapshot(array $servers, array $errors, array $meta, int $storedAt): void
    {
        $this->writeJson(self::SNAPSHOT, ['storedAt' => $storedAt, 'meta' => $meta, 'errors' => $errors, 'servers' => $servers]);
    }

    /** Сдвигает время снимка, не меняя содержимого (откладывает следующую попытку обновления). */
    public function touchSnapshot(): void
    {
        if (is_file($this->path(self::SNAPSHOT))) {
            touch($this->path(self::SNAPSHOT));
        }
    }

    public function publicPayloadPath(): ?string
    {
        $path = $this->path(self::PUBLIC_PAYLOAD);

        return is_file($path) ? $path : null;
    }

    public function writePublicPayload(string $json): void
    {
        $this->filesystem->dumpFile($this->path(self::PUBLIC_PAYLOAD), $json);
    }

    /** @return array<string, array{zeroSince: int, lastSeen: int}> */
    public function readZeroPlayerState(): array
    {
        return $this->readJson(self::ZERO_PLAYER_STATE) ?? [];
    }

    public function writeZeroPlayerState(array $state): void
    {
        $this->writeJson(self::ZERO_PLAYER_STATE, $state);
    }

    private function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    private function readJson(string $name): ?array
    {
        $path = $this->path($name);
        if (!is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    private function writeJson(string $name, array $data): void
    {
        $this->filesystem->dumpFile(
            $this->path($name),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}

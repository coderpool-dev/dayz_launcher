<?php

namespace App\Geo;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Страна сервера по IPv4: ISO-код («RU», «DE») из базы DB-IP «IP to Country Lite» (CC BY 4.0).
 *
 * Файл собирает app:geoip:update. Формат: «GEO4», число диапазонов N (uint32 BE), затем N начал
 * диапазонов (uint32 BE, по возрастанию) и N двухбуквенных кодов. Диапазоны DB-IP покрывают всё
 * пространство IPv4 подряд, поэтому конец диапазона не хранится: адрес относится к последнему
 * диапазону, который начинается не позже него. Соседние диапазоны одной страны склеены.
 */
final class CountryDatabase
{
    private const MAGIC = 'GEO4';
    private const HEADER = 8;
    /** Код DB-IP для зарезервированных и неизвестных адресов. */
    private const UNKNOWN = 'ZZ';

    private ?string $data = null;
    private int $count = 0;

    public function __construct(private readonly string $path)
    {
    }

    /** Название страны по-русски: «RU» → «Россия». Без расширения intl остаётся код. */
    public static function name(string $code): string
    {
        static $names = [];
        if ($code === '' || !class_exists(\Locale::class)) {
            return $code;
        }

        return $names[$code] ??= (\Locale::getDisplayRegion('-' . $code, 'ru') ?: $code);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isAvailable(): bool
    {
        return $this->load();
    }

    /** ISO-код страны или пустая строка, если адрес не IPv4, базы нет или страна неизвестна. */
    public function lookup(string $ip): string
    {
        $long = ip2long(trim($ip));
        if ($long === false || !$this->load()) {
            return '';
        }

        $low = 0;
        $high = $this->count - 1;
        $found = -1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            if ($this->startAt($middle) <= $long) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        if ($found < 0) {
            return '';
        }

        $code = substr($this->data, self::HEADER + $this->count * 4 + $found * 2, 2);

        return $code === self::UNKNOWN ? '' : $code;
    }

    /**
     * Собирает файл базы из строк CSV DB-IP «начало,конец,страна». Строки IPv6 пропускаются.
     *
     * @param iterable<string> $lines
     * @param int $minimumRanges защита от оборванной загрузки: в настоящей базе сотни тысяч диапазонов
     * @return int сколько диапазонов записано после склейки
     */
    public static function build(iterable $lines, string $path, int $minimumRanges = 1000): int
    {
        $starts = [];
        $codes = [];
        $previousEnd = -1;
        foreach ($lines as $line) {
            $parts = explode(',', trim($line));
            if (count($parts) < 3 || str_contains($parts[0], ':')) {
                continue;
            }

            $start = ip2long(trim($parts[0], '" '));
            $end = ip2long(trim($parts[1], '" '));
            $code = strtoupper(trim($parts[2], '" '));
            if ($start === false || $end === false || $start <= $previousEnd || !preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }

            // Дыра между диапазонами — неизвестные адреса, а не страна предыдущего диапазона.
            if ($start > $previousEnd + 1 && $codes !== [] && end($codes) !== self::UNKNOWN) {
                $starts[] = $previousEnd + 1;
                $codes[] = self::UNKNOWN;
            }
            if ($codes === [] || end($codes) !== $code) {
                $starts[] = $start;
                $codes[] = $code;
            }
            $previousEnd = $end;
        }

        if (count($starts) < $minimumRanges) {
            throw new \RuntimeException('GeoIP database looks incomplete: ' . count($starts) . ' ranges');
        }

        $binary = self::MAGIC . pack('N', count($starts)) . pack('N*', ...$starts) . implode('', $codes);
        (new Filesystem())->dumpFile($path, $binary);

        return count($starts);
    }

    private function startAt(int $index): int
    {
        return unpack('N', $this->data, self::HEADER + $index * 4)[1];
    }

    private function load(): bool
    {
        if ($this->data !== null) {
            return $this->count > 0;
        }

        $this->data = '';
        $data = is_file($this->path) ? (string) file_get_contents($this->path) : '';
        if (strlen($data) < self::HEADER || !str_starts_with($data, self::MAGIC)) {
            return false;
        }

        $count = unpack('N', $data, 4)[1];
        if (strlen($data) !== self::HEADER + $count * 6) {
            return false;
        }

        $this->data = $data;
        $this->count = $count;

        return $count > 0;
    }
}

<?php

namespace App\ServerList;

/**
 * Общий бюджет времени на загрузку из всех источников.
 */
final class Deadline
{
    private readonly float $expiresAt;

    public function __construct(private readonly int $seconds)
    {
        $this->expiresAt = microtime(true) + $seconds;
    }

    public function isExceeded(): bool
    {
        return microtime(true) >= $this->expiresAt;
    }

    /** Сколько секунд осталось (не меньше 2, чтобы запрос вообще успел выполниться). */
    public function secondsLeft(): int
    {
        return max(2, (int) floor($this->expiresAt - microtime(true)));
    }

    public function totalSeconds(): int
    {
        return $this->seconds;
    }
}

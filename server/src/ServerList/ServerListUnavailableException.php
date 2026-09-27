<?php

namespace App\ServerList;

/**
 * Список серверов получить не удалось, а сохранённого снимка нет.
 */
final class ServerListUnavailableException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

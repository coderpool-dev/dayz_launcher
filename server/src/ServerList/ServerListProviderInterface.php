<?php

namespace App\ServerList;

interface ServerListProviderInterface
{
    /**
     * Обеспечивает доступность снимка; force обходит проверку свежести в кэширующей обёртке.
     *
     * @throws ServerListUnavailableException
     */
    public function ensureFresh(bool $force = false): string;
}

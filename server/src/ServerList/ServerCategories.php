<?php

namespace App\ServerList;

final class ServerCategories
{
    public function classify(array $server): array
    {
        $name = mb_strtolower((string) ($server['name'] ?? ''));
        $modes = [];
        foreach ([
            'deathmatch' => '~\b(?:death[ -]?match|dm|tdm)\b|дезматч|дезмач~u',
            'pvp' => '~\bpvp\b|пвп~u',
            'pve' => '~\bpve\b|пве~u',
            'rp' => '~\b(?:rp|role[ -]?play)\b|ролеплей~u',
            'survival' => '~\bsurvival\b|выживание~u',
        ] as $mode => $pattern) {
            if (preg_match($pattern, $name)) {
                $modes[] = $mode;
            }
        }

        return [
            'modes' => $modes,
            'style' => preg_match('~\bvanilla\s*\+|\bvanilla[ -]?plus\b~u', $name) ? 'vanilla-plus'
                : (empty($server['modIds']) ? 'vanilla' : 'modded'),
            'hardcore' => (bool) preg_match('~\bhardcore\b|хардкор~u', $name),
            'source' => 'name',
        ];
    }
}

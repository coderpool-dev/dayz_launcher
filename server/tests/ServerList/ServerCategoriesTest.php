<?php

namespace App\Tests\ServerList;

use App\ServerList\ServerCategories;
use PHPUnit\Framework\TestCase;

final class ServerCategoriesTest extends TestCase
{
    public function testExplicitModesAndIndependentStyle(): void
    {
        $categories = new ServerCategories();
        $result = $categories->classify(['name' => 'Deathmatch | PvP | Hardcore | Vanilla+', 'modIds' => ['1']]);
        self::assertSame(['deathmatch', 'pvp'], $result['modes']);
        self::assertSame('vanilla-plus', $result['style']);
        self::assertTrue($result['hardcore']);
        self::assertSame('name', $result['source']);
    }

    public function testUnknownDoesNotPretendToBeSurvivalAndModsDoNotSetMode(): void
    {
        $result = (new ServerCategories())->classify(['name' => 'Admin Paradise', 'mods' => ['PvP'], 'modIds' => ['1']]);
        self::assertSame([], $result['modes']);
        self::assertSame('modded', $result['style']);
    }

    public function testCombinedPveAndPvpAndCyrillicTags(): void
    {
        self::assertSame(['pvp', 'pve'], (new ServerCategories())->classify(['name' => 'ПВЕ + ПВП зоны'])['modes']);
    }
}

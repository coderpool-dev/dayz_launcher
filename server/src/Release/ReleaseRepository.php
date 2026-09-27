<?php

namespace App\Release;

use App\Entity\LauncherRelease;
use Doctrine\ORM\EntityManagerInterface;

final class ReleaseRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Последняя опубликованная версия (сравнение по семантической версии, а не по дате). */
    public function latestPublished(): ?LauncherRelease
    {
        $releases = array_filter(
            $this->em->getRepository(LauncherRelease::class)->findBy(['published' => true]),
            static fn (LauncherRelease $r): bool => $r->hasFile(),
        );

        usort($releases, static fn (LauncherRelease $a, LauncherRelease $b): int => version_compare($b->getVersion(), $a->getVersion()));

        return $releases[0] ?? null;
    }

    public function findPublishedByVersion(string $version): ?LauncherRelease
    {
        return $this->em->getRepository(LauncherRelease::class)->findOneBy(['version' => $version, 'published' => true]);
    }
}

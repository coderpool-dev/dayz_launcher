<?php

namespace App\Stats;

use App\Entity\DailyActivity;
use App\Entity\LauncherInstall;
use App\Entity\PlayEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Сохраняет события лаунчера:
 *  - LauncherInstall — последняя активность установки (онлайн, версия);
 *  - DailyActivity   — пользовался ли лаунчером в этот день с этого IP;
 *  - PlayEvent       — каждое нажатие «Играть».
 */
final class LauncherEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(LauncherEvent $event): void
    {
        $now = $this->clock->now();

        $install = $this->em->find(LauncherInstall::class, $event->launcherId);
        if ($install === null) {
            $install = new LauncherInstall($event->launcherId, $event->ip, $event->version, $now);
            $this->em->persist($install);
        } else {
            $install->touch($event->ip, $event->version, $now);
        }

        $this->rememberDailyActivity($event, $now);

        if ($event->type === LauncherEvent::START) {
            $install->registerStart();
        }

        if ($event->type === LauncherEvent::PLAY) {
            $install->registerPlay();
            $this->em->persist(new PlayEvent($now, $event->launcherId, $event->ip, $event->version, $event->serverId, $event->serverName, $event->serverAddress));
        }

        $this->em->flush();
    }

    private function rememberDailyActivity(LauncherEvent $event, \DateTimeImmutable $now): void
    {
        $day = $now->setTime(0, 0);
        $exists = $this->em->getRepository(DailyActivity::class)->count([
            'day' => $day,
            'launcherId' => $event->launcherId,
            'ip' => $event->ip,
        ]) > 0;

        if (!$exists) {
            $this->em->persist(new DailyActivity($day, $event->launcherId, $event->ip));
        }
    }
}

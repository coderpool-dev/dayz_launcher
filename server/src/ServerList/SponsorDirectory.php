<?php

namespace App\ServerList;

use App\Entity\SponsorServer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Помечает спонсорские серверы (из админки) в выдаче списка.
 */
final class SponsorDirectory
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<array> $servers
     * @return list<array>
     */
    public function apply(array $servers): array
    {
        $sponsors = $this->effectiveSponsors();
        if ($sponsors === []) {
            return $servers;
        }

        foreach ($servers as &$server) {
            $ip = (string) ($server['ip'] ?? '');
            $ports = array_map('intval', [$server['queryPort'] ?? 0, $server['gamePort'] ?? 0, $server['port'] ?? 0]);

            foreach ($sponsors as $sponsor) {
                if ($sponsor->matches($ip, ...$ports)) {
                    $server['sponsor'] = true;
                    $server['sponsorPriority'] = max((int) ($server['sponsorPriority'] ?? 0), $sponsor->getPriority());
                }
            }
        }

        return $servers;
    }

    /** @return list<SponsorServer> */
    private function effectiveSponsors(): array
    {
        $now = new \DateTimeImmutable();

        return array_values(array_filter(
            $this->em->getRepository(SponsorServer::class)->findAll(),
            static fn (SponsorServer $s): bool => $s->isEffective($now),
        ));
    }
}

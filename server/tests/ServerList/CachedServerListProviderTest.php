<?php

namespace App\Tests\ServerList;

use App\ServerList\CachedServerListProvider;
use App\ServerList\ServerListProviderInterface;
use App\ServerList\ServerListStore;
use App\ServerList\ServerListUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class CachedServerListProviderTest extends TestCase
{
    private string $directory;
    private ServerListStore $store;
    private ServerListProviderInterface&MockObject $inner;
    private LockFactory&MockObject $locks;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dayz-cache-test-' . bin2hex(random_bytes(8));
        $this->store = new ServerListStore($this->directory);
        $this->inner = $this->createMock(ServerListProviderInterface::class);
        $this->locks = $this->createMock(LockFactory::class);
    }

    protected function tearDown(): void
    {
        // Тесты создают только один файл в своей уникальной временной директории.
        if (is_file($this->directory . '/servers.json')) {
            unlink($this->directory . '/servers.json');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testFreshSnapshotSkipsUpstreamAndLock(): void
    {
        $this->snapshot(age: 0);
        $this->inner->expects(self::never())->method('ensureFresh');
        $this->locks->expects(self::never())->method('createLock');

        self::assertSame('hit', $this->provider()->ensureFresh());
    }

    #[DataProvider('refreshCases')]
    public function testRefreshesOnMissExpiryOrForce(?int $age, bool $force): void
    {
        if ($age !== null) {
            $this->snapshot($age);
        }
        $this->lock(acquired: true);
        $this->inner->expects(self::once())->method('ensureFresh')->with(true)
            ->willReturnCallback(function (): string {
                $this->snapshot(age: 0);

                return 'refreshed';
            });

        $provider = $this->provider();
        self::assertSame('refreshed', $provider->ensureFresh($force));
        self::assertSame('hit', $provider->ensureFresh());
    }

    public static function refreshCases(): iterable
    {
        yield 'no snapshot' => [null, false];
        yield 'expired snapshot' => [CachedServerListProvider::FRESH_TTL, false];
        yield 'forced fresh snapshot' => [0, true];
    }

    public function testRechecksSnapshotAfterAcquiringLock(): void
    {
        $this->snapshot(CachedServerListProvider::FRESH_TTL);
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturnCallback(function (): bool {
            $this->snapshot(age: 0);

            return true;
        });
        $lock->expects(self::once())->method('release');
        $this->locks->expects(self::once())->method('createLock')->willReturn($lock);
        $this->inner->expects(self::never())->method('ensureFresh');

        self::assertSame('hit-after-lock', $this->provider()->ensureFresh());
    }

    public function testBusyLockServesPreviousSnapshot(): void
    {
        $this->snapshot(CachedServerListProvider::FRESH_TTL);
        $this->lock(acquired: false);
        $this->inner->expects(self::never())->method('ensureFresh');

        self::assertSame('stale-lock', $this->provider()->ensureFresh());
    }

    #[DataProvider('unavailableCases')]
    public function testBusyLockWithoutUsableSnapshotReturns503(?int $age): void
    {
        if ($age !== null) {
            $this->snapshot($age);
        }
        $this->lock(acquired: false);
        $this->inner->expects(self::never())->method('ensureFresh');

        try {
            $this->provider()->ensureFresh();
            self::fail('Expected an unavailable server list');
        } catch (ServerListUnavailableException $e) {
            self::assertSame(503, $e->httpStatus);
        }
    }

    public static function unavailableCases(): iterable
    {
        yield 'no snapshot' => [null];
        yield 'stale limit reached' => [CachedServerListProvider::STALE_TTL];
    }

    public function testSourceFailureKeepsSnapshotAndDelaysRetry(): void
    {
        $this->snapshot(CachedServerListProvider::FRESH_TTL);
        $before = $this->store->readSnapshot();
        $this->lock(acquired: true);
        $this->inner->expects(self::once())->method('ensureFresh')->with(true)
            ->willThrowException(new ServerListUnavailableException('Upstream unavailable', 502));

        $provider = $this->provider();
        self::assertSame('stale-error', $provider->ensureFresh());
        self::assertSame($before, $this->store->readSnapshot());
        self::assertSame('hit', $provider->ensureFresh());
    }

    public function testSourceFailureWithoutSnapshotPreservesErrorAndReleasesLock(): void
    {
        $this->lock(acquired: true);
        $error = new ServerListUnavailableException('Upstream unavailable', 502, [['source' => 'dzsa']]);
        $this->inner->expects(self::once())->method('ensureFresh')->willThrowException($error);

        try {
            $this->provider()->ensureFresh();
            self::fail('Expected an upstream error');
        } catch (ServerListUnavailableException $e) {
            self::assertSame($error, $e);
        }
    }

    public function testUnexpectedFailureIsNotHiddenByStaleCache(): void
    {
        $this->snapshot(CachedServerListProvider::FRESH_TTL);
        $this->lock(acquired: true);
        $this->inner->expects(self::once())->method('ensureFresh')
            ->willThrowException(new \RuntimeException('Disk write failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Disk write failed');
        $this->provider()->ensureFresh();
    }

    private function provider(): CachedServerListProvider
    {
        return new CachedServerListProvider($this->inner, $this->store, $this->locks);
    }

    private function snapshot(int $age): void
    {
        $this->store->writeSnapshot([['name' => 'Test server']], [], [], time() - $age);
        touch($this->directory . '/servers.json', time() - $age);
    }

    private function lock(bool $acquired): void
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->expects(self::once())->method('acquire')->willReturn($acquired);
        $lock->expects($acquired ? self::once() : self::never())->method('release');
        $this->locks->expects(self::once())->method('createLock')
            ->with('server-list-refresh', 55)->willReturn($lock);
    }
}

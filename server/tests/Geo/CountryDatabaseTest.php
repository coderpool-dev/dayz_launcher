<?php

namespace App\Tests\Geo;

use App\Geo\CountryDatabase;
use PHPUnit\Framework\TestCase;

final class CountryDatabaseTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/country-db-test-' . bin2hex(random_bytes(4)) . '.bin';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testLooksUpRangesMergesNeighboursAndSkipsIpv6(): void
    {
        $ranges = CountryDatabase::build([
            "0.0.0.0,0.255.255.255,ZZ\n",
            "1.0.0.0,1.0.0.255,AU\n",
            "5.0.0.0,5.0.0.255,RU\n",
            "5.0.1.0,5.0.1.255,RU\n",
            "5.0.2.0,5.0.2.255,DE\n",
            "2001:200::,2001:200:ffff:ffff:ffff:ffff:ffff:ffff,JP\n",
            "\"9.0.0.0\",\"255.255.255.255\",\"US\"\n",
        ], $this->path, 1);

        // ZZ, AU, дыра 1.0.1.0–4.255.255.255 (ZZ), RU (две строки склеены), DE, дыра (ZZ), US.
        self::assertSame(7, $ranges);
        $countries = new CountryDatabase($this->path);
        self::assertSame('AU', $countries->lookup('1.0.0.7'));
        self::assertSame('RU', $countries->lookup('5.0.0.1'));
        self::assertSame('RU', $countries->lookup('5.0.1.255'));
        self::assertSame('DE', $countries->lookup(' 5.0.2.0 '));
        self::assertSame('US', $countries->lookup('255.255.255.255'));
        self::assertSame('', $countries->lookup('0.0.0.1'), 'reserved range');
        self::assertSame('', $countries->lookup('3.3.3.3'), 'gap between ranges is unknown, not the previous country');
        self::assertSame('', $countries->lookup('6.0.0.0'));
        self::assertSame('', $countries->lookup('2001:200::1'));
        self::assertSame('', $countries->lookup('not-an-ip'));
    }

    public function testMissingOrBrokenFileMeansUnknownCountry(): void
    {
        self::assertSame('', (new CountryDatabase($this->path))->lookup('5.0.0.1'));

        file_put_contents($this->path, 'GEO4' . pack('N', 5) . 'short');
        $countries = new CountryDatabase($this->path);
        self::assertFalse($countries->isAvailable());
        self::assertSame('', $countries->lookup('5.0.0.1'));
    }

    public function testRefusesSuspiciouslySmallDownloads(): void
    {
        $this->expectException(\RuntimeException::class);
        CountryDatabase::build(["1.0.0.0,1.0.0.255,AU\n"], $this->path);
    }

    public function testRussianCountryNames(): void
    {
        if (!class_exists(\Locale::class)) {
            self::markTestSkipped('intl is not installed');
        }

        self::assertSame('Россия', CountryDatabase::name('RU'));
        self::assertSame('Германия', CountryDatabase::name('DE'));
        self::assertSame('', CountryDatabase::name(''));
    }
}

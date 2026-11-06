<?php

namespace Fleetbase\Pod\Tests;

use Fleetbase\Pod\Support\AdminAreas\AdminAreaCsvReader;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaMatcher;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaSet;
use Fleetbase\Pod\Support\Countries\CountryProfiles;
use Fleetbase\Pod\Support\Import\BeneficiaryNormalizer;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static ?AdminAreaSet $sierraLeone = null;

    protected static function config(): array
    {
        return require __DIR__ . '/../config/pod.php';
    }

    protected static function profiles(): CountryProfiles
    {
        $config = self::config();

        return new CountryProfiles($config['countries'], 'SL');
    }

    /**
     * The real Sierra Leone seed list shipped with the package.
     */
    protected static function sierraLeone(): AdminAreaSet
    {
        return self::$sierraLeone ??= (new AdminAreaCsvReader())->read(
            __DIR__ . '/../data/seed/sierra-leone/admin-areas.csv',
            'SL'
        );
    }

    protected static function normalizer(int $year = 2026): BeneficiaryNormalizer
    {
        $areas = self::sierraLeone();

        return new BeneficiaryNormalizer(
            self::profiles()->get('SL'),
            new AdminAreaMatcher($areas),
            $areas,
            'test-id-hash-key',
            $year,
        );
    }

    protected static function fixture(string $name): string
    {
        return __DIR__ . '/Fixtures/' . $name;
    }
}

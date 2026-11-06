<?php

namespace Fleetbase\Pod\Services;

use Fleetbase\Pod\Models\AdminArea;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaCsvReader;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaMatcher;
use Fleetbase\Pod\Support\AdminAreas\AdminAreaSet;
use Fleetbase\Pod\Support\Countries\CountryProfiles;

/**
 * Loads a country's admin areas: from the database once seeded, otherwise
 * straight from the package's seed file.
 */
class AdminAreaRepository
{
    /** @var array<string, AdminAreaSet> */
    private array $loaded = [];

    public function __construct(private CountryProfiles $countries)
    {
    }

    public function forCountry(string $country): AdminAreaSet
    {
        $country = strtoupper($country);

        return $this->loaded[$country] ??= $this->load($country);
    }

    public function matcher(string $country): AdminAreaMatcher
    {
        return new AdminAreaMatcher($this->forCountry($country), (float) config('pod.import.place_match_threshold', 0.85));
    }

    /**
     * Forget cached sets after the admin areas change.
     */
    public function flush(): void
    {
        $this->loaded = [];
    }

    public function seedFile(string $country): ?string
    {
        $file = $this->countries->get($country)->seedFile;

        return $file ? dirname(__DIR__, 2) . '/data/' . $file : null;
    }

    private function load(string $country): AdminAreaSet
    {
        $rows = AdminArea::query()->where('country', $country)->orderBy('level')->orderBy('pcode')->get();
        if ($rows->isNotEmpty()) {
            return new AdminAreaSet($rows->map(fn (AdminArea $area) => $area->toValue()));
        }

        $seed = $this->seedFile($country);
        if ($seed && is_readable($seed)) {
            return (new AdminAreaCsvReader())->read($seed, $country);
        }

        return new AdminAreaSet();
    }
}

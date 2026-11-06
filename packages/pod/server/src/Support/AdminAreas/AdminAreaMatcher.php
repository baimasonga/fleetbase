<?php

namespace Fleetbase\Pod\Support\AdminAreas;

use Fleetbase\Pod\Support\Text\Text;

/**
 * Matches typed place names from spreadsheets ("Bo Dist.", "Kakua chiefdom",
 * "North West") to official admin areas, top level first, each level searched
 * inside the area matched above it.
 */
final class AdminAreaMatcher
{
    /**
     * Generic words that people add to place names in lists.
     */
    private const NOISE = ['chiefdom', 'chiefdoms', 'district', 'dist', 'region', 'province', 'section', 'sec', 'county', 'state', 'lga', 'ward', 'community', 'village', 'town', 'area council'];

    /**
     * Common alternative names, as comparison keys: alias => official name key.
     *
     * @var array<string, string>
     */
    private array $aliases;

    public function __construct(
        private AdminAreaSet $areas,
        private float $threshold = 0.85,
        array $aliases = [],
    ) {
        $defaults = [
            // Sierra Leone regions as commonly written
            'north west'          => 'north western',
            'northwest'           => 'north western',
            'north western province' => 'north western',
            'western area'        => 'western',
            'eastern province'    => 'eastern',
            'northern province'   => 'northern',
            'southern province'   => 'southern',
            'east'                => 'eastern',
            'north'               => 'northern',
            'south'               => 'southern',
            // Sierra Leone districts
            'western rural'       => 'western area rural',
            'western urban'       => 'western area urban',
            'wa rural'            => 'western area rural',
            'wa urban'            => 'western area urban',
        ];

        $this->aliases = [];
        foreach ($defaults + $aliases as $alias => $official) {
            $this->aliases[$this->key($alias)] = $this->key($official);
        }
    }

    /**
     * Match a row's place names.
     *
     * @param array<int, ?string> $names level => typed name (missing levels may be omitted or null)
     *
     * @return array<int, PlaceMatch> level => match, for each level that was given
     */
    public function matchHierarchy(array $names): array
    {
        ksort($names);
        $results  = [];
        $ancestor = null;

        foreach ($names as $level => $name) {
            $match            = $this->matchOne((int) $level, $name, $ancestor);
            $results[$level] = $match;
            if ($match->matched()) {
                $ancestor = $match->area->pcode;
            }
        }

        return $results;
    }

    /**
     * Match one name at a level, optionally only under an ancestor area.
     */
    public function matchOne(int $level, ?string $name, ?string $ancestorPcode = null): PlaceMatch
    {
        $input = Text::squish($name);
        $key   = $this->key($input);
        if ($key === '') {
            return new PlaceMatch($level, $input, PlaceMatch::EMPTY);
        }

        $candidates = $this->areas->atLevelUnder($level, $ancestorPcode);
        if (!$candidates) {
            return new PlaceMatch($level, $input, PlaceMatch::NONE);
        }

        $aliasTarget = $this->aliases[$key] ?? null;
        $prefixHits  = [];
        $scored      = [];

        foreach ($candidates as $area) {
            $areaKey = $this->key($area->name);

            if ($areaKey === $key) {
                return new PlaceMatch($level, $input, PlaceMatch::EXACT, $area, 1.0);
            }

            if (($aliasTarget !== null && $areaKey === $aliasTarget)
                || ($area->sourceName !== null && $this->key($area->sourceName) === $key)) {
                return new PlaceMatch($level, $input, PlaceMatch::ALIAS, $area, 1.0);
            }

            // The official name was cut off in the source (e.g. "Folosaba Dembel"),
            // and the typed name is the full form ("Folosaba Dembelia").
            if ($area->isPossiblyTruncated() && strlen($areaKey) >= 8 && str_starts_with($key, $areaKey)) {
                $prefixHits[] = $area;
            }

            $scored[] = [$area, Text::jaroWinkler($key, $areaKey)];
        }

        if (count($prefixHits) === 1) {
            return new PlaceMatch($level, $input, PlaceMatch::PREFIX, $prefixHits[0], 0.99);
        }

        usort($scored, fn ($a, $b) => $b[1] <=> $a[1]);
        $suggestions = array_map(fn ($pair) => $pair[0]->name, array_slice($scored, 0, 3));
        [$best, $bestScore] = $scored[0];
        $secondScore        = $scored[1][1] ?? 0.0;

        // Accept only a clear winner: two near-equal candidates is a guess.
        if ($bestScore >= $this->threshold && ($bestScore - $secondScore) >= 0.02) {
            return new PlaceMatch($level, $input, PlaceMatch::FUZZY, $best, $bestScore, $suggestions);
        }

        return new PlaceMatch($level, $input, PlaceMatch::NONE, null, $bestScore, $suggestions);
    }

    private function key(string $value): string
    {
        $key = ' ' . Text::key($value) . ' ';
        foreach (self::NOISE as $word) {
            $key = str_replace(' ' . $word . ' ', ' ', $key);
        }

        return trim(preg_replace('/\s+/', ' ', $key) ?? '');
    }
}

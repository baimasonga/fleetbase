<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\AdminAreas\AdminAreaMatcher;
use Fleetbase\Pod\Support\AdminAreas\PlaceMatch;
use Fleetbase\Pod\Tests\TestCase;

final class AdminAreasTest extends TestCase
{
    public function testTheSierraLeoneSeedListHasTheOfficialCounts(): void
    {
        $areas = self::sierraLeone();

        $this->assertCount(5, $areas->atLevel(1));
        $this->assertCount(16, $areas->atLevel(2));
        $this->assertCount(167, $areas->atLevel(3));
        $this->assertCount(1316, $areas->atLevel(4));
        $this->assertSame(1504, count($areas));
    }

    public function testEveryAreaBelowARegionHasAParentInTheList(): void
    {
        $areas = self::sierraLeone();

        foreach ($areas->all() as $area) {
            if ($area->level === 1) {
                $this->assertNull($area->parentPcode, $area->pcode);
                continue;
            }
            $parent = $areas->find($area->parentPcode);
            $this->assertNotNull($parent, "Parent of {$area->pcode}");
            $this->assertSame($area->level - 1, $parent->level, $area->pcode);
        }
    }

    public function testFalabaIsCorrectedAndTheSourceSpellingKept(): void
    {
        $falaba = self::sierraLeone()->find('SL0206');

        $this->assertSame('Falaba', $falaba->name);
        $this->assertSame('Fabala', $falaba->sourceName);
    }

    public function testLineageGoesFromRegionDown(): void
    {
        $lineage = self::sierraLeone()->lineage('SL01010101');

        $this->assertSame(['SL01', 'SL0101', 'SL010101', 'SL01010101'], array_map(fn ($a) => $a->pcode, array_values($lineage)));
        $this->assertSame('Eastern', $lineage[1]->name);
        $this->assertSame('Kailahun', $lineage[2]->name);
    }

    public function testMatchesDistrictsWithNoiseWordsAndDifferentCase(): void
    {
        $matcher = new AdminAreaMatcher(self::sierraLeone());

        $match = $matcher->matchOne(2, 'BO DISTRICT');
        $this->assertSame(PlaceMatch::EXACT, $match->method);
        $this->assertSame('SL0301', $match->area->pcode);
    }

    public function testMatchesCommonRegionNames(): void
    {
        $matcher = new AdminAreaMatcher(self::sierraLeone());

        $this->assertSame('SL05', $matcher->matchOne(1, 'North West')->area->pcode);
        $this->assertSame('SL04', $matcher->matchOne(1, 'Western Area')->area->pcode);
        $this->assertSame('SL01', $matcher->matchOne(1, 'Eastern Province')->area->pcode);
    }

    public function testMatchesTheSourceSpellingAsAnAlias(): void
    {
        $match = (new AdminAreaMatcher(self::sierraLeone()))->matchOne(2, 'Fabala');

        $this->assertSame(PlaceMatch::ALIAS, $match->method);
        $this->assertSame('Falaba', $match->area->name);
    }

    public function testMatchesTheFullNameOfAChiefdomCutOffInTheSource(): void
    {
        $matcher = new AdminAreaMatcher(self::sierraLeone());

        $match = $matcher->matchOne(3, 'Folosaba Dembelia', 'SL0206');
        $this->assertSame(PlaceMatch::PREFIX, $match->method);
        $this->assertSame('Folosaba Dembel', $match->area->name);
        $this->assertTrue($match->isConfident());
    }

    public function testMatchesAMisspeltDistrictButFlagsItForChecking(): void
    {
        $match = (new AdminAreaMatcher(self::sierraLeone()))->matchOne(2, 'Kenama');

        $this->assertSame(PlaceMatch::FUZZY, $match->method);
        $this->assertSame('Kenema', $match->area->name);
        $this->assertFalse($match->isConfident());
    }

    public function testDoesNotGuessAnUnknownPlace(): void
    {
        $match = (new AdminAreaMatcher(self::sierraLeone()))->matchOne(2, 'Lagos');

        $this->assertSame(PlaceMatch::NONE, $match->method);
        $this->assertNull($match->area);
        $this->assertCount(3, $match->suggestions);
    }

    public function testSearchesChiefdomsOnlyInsideTheMatchedDistrict(): void
    {
        $matcher = new AdminAreaMatcher(self::sierraLeone());
        $results = $matcher->matchHierarchy([2 => 'Kailahun', 3 => 'Dea']);

        $this->assertSame('SL010101', $results[3]->area->pcode);

        // "Dea" is a Kailahun chiefdom, so it isn't found under Bo.
        $results = $matcher->matchHierarchy([2 => 'Bo', 3 => 'Dea']);
        $this->assertFalse($results[3]->matched());
    }
}

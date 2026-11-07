<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Import\ColumnMapper;
use Fleetbase\Pod\Tests\TestCase;

final class ColumnMapperTest extends TestCase
{
    public function testSuggestsAMappingForATypicalProgrammeList(): void
    {
        $headers = ['S/N', 'Name of Farmer', 'Sex (M/F)', 'Age', 'Phone No.', 'Type of ID', 'ID No.', 'District', 'Chiefdom', 'Section', 'Village/Town', 'FBO'];

        $mapping = (new ColumnMapper())->suggest($headers);

        $this->assertSame([
            'full_name' => 'Name of Farmer',
            'sex'       => 'Sex (M/F)',
            'age'       => 'Age',
            'phone'     => 'Phone No.',
            'id_type'   => 'Type of ID',
            'id_number' => 'ID No.',
            'district'  => 'District',
            'chiefdom'  => 'Chiefdom',
            'section'   => 'Section',
            'village'   => 'Village/Town',
            'group'     => 'FBO',
        ], $this->sorted($mapping, $headers));
    }

    public function testTreatsNameNextToSurnameAsTheFirstName(): void
    {
        $mapping = (new ColumnMapper())->suggest(['Name', 'Surname', 'Gender', 'District']);

        $this->assertSame('Name', $mapping['first_name']);
        $this->assertSame('Surname', $mapping['last_name']);
        $this->assertArrayNotHasKey('full_name', $mapping);
    }

    public function testValidationNamesWhatIsMissing(): void
    {
        $problems = (new ColumnMapper())->validate(['phone' => 'Phone'], ['Phone']);

        $this->assertCount(3, $problems);
        $this->assertStringContainsString('name', $problems[0]);
    }

    public function testValidationRejectsAColumnUsedTwice(): void
    {
        $problems = (new ColumnMapper())->validate(['full_name' => 'Name', 'village' => 'Name', 'district' => 'D', 'sex' => 'S'], ['Name', 'D', 'S']);

        $this->assertSame(['Column [Name] is mapped to more than one field.'], $problems);
    }

    public function testApplyTurnsCellsIntoCleanStrings(): void
    {
        $row = (new ColumnMapper())->apply(['phone' => 'Phone', 'age' => 'Age'], ['Phone' => 76123456.0, 'Age' => ' 34 ']);

        $this->assertSame(['phone' => '76123456', 'age' => '34'], $row);
    }

    private function sorted(array $mapping, array $headers): array
    {
        uasort($mapping, fn ($a, $b) => array_search($a, $headers) <=> array_search($b, $headers));

        return $mapping;
    }
}

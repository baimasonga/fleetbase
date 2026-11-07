<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Import\RowIssue;
use Fleetbase\Pod\Tests\TestCase;

final class BeneficiaryNormalizerTest extends TestCase
{
    private function codes(array $issues, ?string $severity = null): array
    {
        $issues = $severity ? array_filter($issues, fn (RowIssue $i) => $i->severity === $severity) : $issues;

        return array_values(array_map(fn (RowIssue $i) => $i->code, $issues));
    }

    private function row(array $overrides = []): array
    {
        return $overrides + [
            'full_name' => 'aminata  KAMARA',
            'sex'       => 'F',
            'age'       => '34',
            'phone'     => '076123456',
            'id_type'   => "Voter's Card",
            'id_number' => '12-345-6789',
            'district'  => 'Kailahun',
            'chiefdom'  => 'Dea',
            'village'   => 'baiwalla',
        ];
    }

    public function testCleansACompleteRow(): void
    {
        $row = self::normalizer()->normalize(5, $this->row());

        $this->assertFalse($row->hasErrors(), json_encode($row->toArray()['issues']));
        $this->assertSame('Aminata Kamara', $row->get('name'));
        $this->assertSame('female', $row->get('sex'));
        $this->assertSame(1992, $row->get('birth_year'));
        $this->assertSame('+23276123456', $row->get('phone'));
        $this->assertSame('voter_id', $row->get('id_type'));
        $this->assertSame('6789', $row->get('id_last4'));
        $this->assertSame(64, strlen($row->get('id_hash')));
        $this->assertSame('Baiwalla', $row->get('village'));
        $this->assertSame('SL01', $row->get('admin_1'), 'region filled in from the district');
        $this->assertSame('SL0101', $row->get('admin_2'));
        $this->assertSame('SL010101', $row->get('admin_3'));
        $this->assertSame('SL010101', $row->deepestPcode());
    }

    public function testNeverKeepsTheFullIdNumber(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['id_number' => 'AB1234567']));

        $this->assertStringNotContainsString('AB1234567', json_encode($row->data));
        $this->assertSame('4567', $row->get('id_last4'));
    }

    public function testTheSameIdGivesTheSameHashWhateverTheFormatting(): void
    {
        $a = self::normalizer()->normalize(2, $this->row(['id_number' => '12-345-6789']));
        $b = self::normalizer()->normalize(3, $this->row(['id_number' => '123456789']));

        $this->assertSame($a->get('id_hash'), $b->get('id_hash'));
    }

    public function testReadsPhoneNumbersInTheFormsFoundInSpreadsheets(): void
    {
        $forms = [
            '76123456'           => '+23276123456', // Excel dropped the leading 0
            '076 123 456'        => '+23276123456',
            '+232 76 123456'     => '+23276123456',
            '0023276123456'      => '+23276123456',
            '232-76-123456'      => '+23276123456',
        ];

        foreach ($forms as $input => $expected) {
            $row = self::normalizer()->normalize(2, $this->row(['phone' => $input]));
            $this->assertSame($expected, $row->get('phone'), "Phone {$input}");
        }
    }

    public function testKeepsTheFirstOfSeveralPhoneNumbers(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['phone' => '076123456 / 088654321']));

        $this->assertSame('+23276123456', $row->get('phone'));
        $this->assertSame('+23288654321', $row->get('phone_extra'));
        $this->assertContains('phone_multiple', $this->codes($row->issues));
    }

    public function testAnInvalidPhoneIsAWarningAndIsDropped(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['phone' => '12345']));

        $this->assertNull($row->get('phone'));
        $this->assertFalse($row->hasErrors());
        $this->assertContains('phone_invalid', $this->codes($row->issues, RowIssue::WARNING));
    }

    public function testBuildsTheNameFromParts(): void
    {
        $row = self::normalizer()->normalize(2, ['first_name' => 'mohamed', 'middle_name' => '', 'last_name' => 'BANGURA'] + $this->row(['full_name' => '']));

        $this->assertSame('Mohamed Bangura', $row->get('name'));
    }

    public function testRequiresNameSexAndDistrict(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['full_name' => '', 'sex' => '', 'district' => '']));

        $this->assertEqualsCanonicalizing(['name_missing', 'sex_missing', 'district_missing'], $this->codes($row->issues, RowIssue::ERROR));
    }

    public function testRejectsAnUnknownSexValue(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['sex' => 'X']));

        $this->assertContains('sex_unknown', $this->codes($row->issues, RowIssue::ERROR));
    }

    public function testWorksOutTheBirthYearFromYearDateOrAge(): void
    {
        $n = self::normalizer(2026);

        $this->assertSame(1980, $n->normalize(2, $this->row(['age' => '', 'birth_year' => '1980']))->get('birth_year'));
        $this->assertSame(1985, $n->normalize(2, $this->row(['age' => '', 'date_of_birth' => '12/03/1985']))->get('birth_year'));
        $this->assertSame(1985, $n->normalize(2, $this->row(['age' => '', 'date_of_birth' => '1985-03-12']))->get('birth_year'));
        $this->assertSame(1985, $n->normalize(2, $this->row(['age' => '', 'date_of_birth' => '31118']))->get('birth_year'), 'Excel date number');
        $this->assertSame(1991, $n->normalize(2, $this->row(['age' => '35']))->get('birth_year'));
    }

    public function testRejectsImpossibleAgesAndWarnsOnYoungFarmers(): void
    {
        $this->assertContains('age_out_of_range', $this->codes(self::normalizer()->normalize(2, $this->row(['age' => '7']))->issues, RowIssue::ERROR));
        $this->assertContains('age_out_of_range', $this->codes(self::normalizer()->normalize(2, $this->row(['age' => '130']))->issues, RowIssue::ERROR));
        $this->assertContains('age_young', $this->codes(self::normalizer()->normalize(2, $this->row(['age' => '13']))->issues, RowIssue::WARNING));
    }

    public function testAnUnknownDistrictIsAnErrorWithSuggestions(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['district' => 'Atlantis', 'chiefdom' => '']));

        $errors = array_values(array_filter($row->issues, fn ($i) => $i->code === 'place_unmatched'));
        $this->assertTrue($row->hasErrors());
        $this->assertStringContainsString('Closest:', $errors[0]->message);
    }

    public function testAnUnknownChiefdomIsOnlyAWarning(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['chiefdom' => 'Nowhere']));

        $this->assertFalse($row->hasErrors());
        $this->assertSame('SL0101', $row->get('admin_2'));
        $this->assertNull($row->get('admin_3'));
        $this->assertContains('place_unmatched', $this->codes($row->issues, RowIssue::WARNING));
    }

    public function testAWrongRegionDoesNotHideTheRightDistrict(): void
    {
        $row = self::normalizer()->normalize(2, $this->row(['region' => 'Southern', 'district' => 'Kailahun']));

        $this->assertFalse($row->hasErrors());
        $this->assertSame('SL01', $row->get('admin_1'));
        $this->assertContains('region_mismatch', $this->codes($row->issues));
    }

    public function testRecognisesIdTypesBySierraLeoneNames(): void
    {
        $types = ['NIN' => 'national_id', 'National ID' => 'national_id', 'Voter ID' => 'voter_id', 'passport' => 'passport', "Driver's licence" => 'drivers_licence', 'Library card' => 'other'];

        foreach ($types as $raw => $expected) {
            $row = self::normalizer()->normalize(2, $this->row(['id_type' => $raw]));
            $this->assertSame($expected, $row->get('id_type'), $raw);
        }
    }

    public function testSwapsLatitudeAndLongitudeWhenTheyAreClearlyReversed(): void
    {
        // Kailahun town is about 8.28 N, 10.57 W.
        $row = self::normalizer()->normalize(2, $this->row(['latitude' => '-10.57', 'longitude' => '8.28']));

        $this->assertSame(8.28, $row->get('latitude'));
        $this->assertSame(-10.57, $row->get('longitude'));
        $this->assertContains('gps_swapped', $this->codes($row->issues));
    }
}

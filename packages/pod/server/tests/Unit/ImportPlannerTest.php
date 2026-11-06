<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Import\ColumnMapper;
use Fleetbase\Pod\Support\Import\DuplicateDetector;
use Fleetbase\Pod\Support\Import\FarmerListTemplate;
use Fleetbase\Pod\Support\Import\ImportPlan;
use Fleetbase\Pod\Support\Import\ImportPlanner;
use Fleetbase\Pod\Support\Import\SpreadsheetReader;
use Fleetbase\Pod\Tests\TestCase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ImportPlannerTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function planner(): ImportPlanner
    {
        return new ImportPlanner(new ColumnMapper(), self::normalizer(), new DuplicateDetector());
    }

    /**
     * Write rows to a real .xlsx file, the way a partner's list arrives.
     */
    private function xlsx(array $rows): string
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1', true);
        $path          = tempnam(sys_get_temp_dir(), 'pod') . '.xlsx';
        $this->files[] = $path;
        (new Xlsx($book))->save($path);

        return $path;
    }

    public function testPlansAMessyPartnerListEndToEnd(): void
    {
        $path = $this->xlsx([
            ['S/N', 'Name of Farmer', 'Sex', 'Age', 'Phone No.', 'District', 'Chiefdom', 'Village'],
            [1, 'Aminata Kamara', 'F', 34, 76123456, 'Kailahun', 'Dea', 'Baiwalla'],
            [2, 'Mohamed Bangura', 'M', 41, '088 654 321', 'Bo', 'Kakua', 'Gerihun'],
            [3, 'AMINATA KAMARA', 'female', 34, '', 'kailahun district', 'Dea Chiefdom', 'baiwalla'], // exact repeat of row 2
            [],                                                                                     // blank row, skipped
            [5, 'Fatmata Sesay', 'F', 29, '076123456', 'Kenama', 'Nongowa', 'Hangha'],           // misspelt district, same phone as row 2
            [6, '', 'M', 50, '', 'Kono', '', 'Koidu'],                                             // no name
            [7, 'Ibrahim Conteh', 'M', 38, '', 'Atlantis', '', 'Nowhere'],                         // unknown district
        ]);

        $reader = new SpreadsheetReader();
        $sheet  = $reader->read($path);
        $this->assertSame([2, 3, 4, 6, 7, 8], array_keys($sheet->rows), 'spreadsheet row numbers kept, blank row skipped');

        $mapping = (new ColumnMapper())->suggest($sheet->headers);
        $plan    = $this->planner()->plan($sheet, $mapping);

        $this->assertSame(ImportPlan::ACCEPTED, $plan->rows[2]['status']);
        $this->assertSame(ImportPlan::ACCEPTED, $plan->rows[3]['status']);
        $this->assertSame(ImportPlan::MERGED, $plan->rows[4]['status']);
        $this->assertSame(2, $plan->rows[4]['duplicate_of_row']);
        $this->assertSame(ImportPlan::REVIEW, $plan->rows[6]['status'], 'same phone as row 2');
        $this->assertSame([DuplicateDetector::SAME_PHONE], $plan->rows[6]['review'][0]['reasons']);
        $this->assertSame('SL0102', $plan->rows[6]['row']->get('admin_2'), 'Kenama matched to Kenema');
        $this->assertSame(ImportPlan::REJECTED, $plan->rows[7]['status']);
        $this->assertSame(ImportPlan::REJECTED, $plan->rows[8]['status']);

        $summary = $plan->summary();
        $this->assertSame(6, $summary['total']);
        $this->assertSame(2, $summary[ImportPlan::ACCEPTED]);
        $this->assertSame(1, $summary[ImportPlan::MERGED]);
        $this->assertSame(1, $summary[ImportPlan::REVIEW]);
        $this->assertSame(2, $summary[ImportPlan::REJECTED]);
    }

    public function testMarksPeopleAlreadyRegisteredAndFlagsSimilarNames(): void
    {
        $path  = $this->xlsx([
            ['Full Name', 'Sex', 'Year of Birth', 'District', 'Chiefdom', 'Village'],
            ['Aminata Kamara', 'F', 1992, 'Kailahun', 'Dea', 'Baiwalla'],
            ['Aminatta Kamara', 'F', 1993, 'Kailahun', 'Dea', 'Baiwalla'],
        ]);
        $sheet = (new SpreadsheetReader())->read($path);

        $normalizer = self::normalizer();
        $existing   = $normalizer->normalize(0, ['full_name' => 'Aminata Kamara', 'sex' => 'F', 'birth_year' => '1992', 'district' => 'Kailahun', 'chiefdom' => 'Dea', 'village' => 'Baiwalla']);

        $plan = $this->planner()->plan($sheet, (new ColumnMapper())->suggest($sheet->headers), [
            'ben_001' => ['data' => $existing->data, 'place' => $existing->deepestPcode()],
        ]);

        $this->assertSame(ImportPlan::EXISTS, $plan->rows[2]['status']);
        $this->assertSame('ben_001', $plan->rows[2]['duplicate_of_existing']);
        $this->assertSame(ImportPlan::REVIEW, $plan->rows[3]['status']);
        $this->assertSame('ben_001', $plan->rows[3]['review'][0]['other_existing']);
        $this->assertSame([DuplicateDetector::SIMILAR_NAME], $plan->rows[3]['review'][0]['reasons']);
    }

    public function testDoesNotFlagDifferentPeopleWithCommonSurnames(): void
    {
        $path  = $this->xlsx([
            ['Full Name', 'Sex', 'Age', 'District', 'Chiefdom', 'Village'],
            ['Aminata Kamara', 'F', 34, 'Kailahun', 'Dea', 'Baiwalla'],
            ['Isatu Kamara', 'F', 34, 'Kailahun', 'Dea', 'Baiwalla'],
            ['Aminata Kamara', 'M', 34, 'Kailahun', 'Dea', 'Baiwalla'],   // different sex
            ['Aminata Kamara', 'F', 60, 'Kailahun', 'Dea', 'Baiwalla'],   // 26 years apart
        ]);
        $sheet = (new SpreadsheetReader())->read($path);

        $plan = $this->planner()->plan($sheet, (new ColumnMapper())->suggest($sheet->headers));

        $this->assertSame(4, $plan->summary()[ImportPlan::ACCEPTED]);
    }

    public function testRefusesAMappingWithoutRequiredColumns(): void
    {
        $sheet = (new SpreadsheetReader())->read($this->xlsx([['Phone'], ['076123456']]));

        $this->expectException(\InvalidArgumentException::class);
        $this->planner()->plan($sheet, ['phone' => 'Phone']);
    }

    public function testReadsCsvFiles(): void
    {
        $path          = tempnam(sys_get_temp_dir(), 'pod') . '.csv';
        $this->files[] = $path;
        file_put_contents($path, "Name,Sex,Age,District\nAminata Kamara,F,34,Kailahun\n");

        $sheet = (new SpreadsheetReader())->read($path);

        $this->assertSame(['Name', 'Sex', 'Age', 'District'], $sheet->headers);
        $this->assertSame(['Aminata Kamara', 'F', 34, 'Kailahun'], $sheet->rows[2]);
    }

    public function testTheSierraLeoneTemplateRoundTripsWithoutMapping(): void
    {
        $country = self::profiles()->get('SL');
        $book    = (new FarmerListTemplate())->build($country, self::sierraLeone());

        $farmers = $book->getSheetByName('Farmers');
        $farmers->fromArray([['', 'Aminata Kamara', 'Female', 34, '', '076123456', 'Voter ID card', '123456789', 'Eastern', 'Kailahun', 'Dea', '', 'Baiwalla']], null, 'A2', true);
        $farmers->getCell('F2')->setValueExplicit('076123456', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        $path          = tempnam(sys_get_temp_dir(), 'pod') . '.xlsx';
        $this->files[] = $path;
        (new Xlsx($book))->save($path);

        $reader = new SpreadsheetReader();
        $this->assertSame(['Farmers', 'Lists', 'Instructions'], $reader->sheetNames($path));

        $sheet = $reader->read($path, 'Farmers');
        $this->assertTrue(FarmerListTemplate::matches($country, $sheet->headers));
        $this->assertSame('Chiefdom', $sheet->headers[10]);

        $plan = $this->planner()->plan($sheet, FarmerListTemplate::mapping($country));
        $row  = $plan->rows[2]['row'];

        $this->assertSame(ImportPlan::ACCEPTED, $plan->rows[2]['status'], json_encode($row->toArray()['issues']));
        $this->assertSame('+23276123456', $row->get('phone'));
        $this->assertSame('voter_id', $row->get('id_type'));
        $this->assertSame('SL010101', $row->get('admin_3'));

        // The drop-down lists are filled from the seed list.
        $lists = $book->getSheetByName('Lists');
        $this->assertSame('district', $lists->getCell('D1')->getValue());
        $this->assertSame('Bo', $lists->getCell('D2')->getValue());
        $this->assertNotNull($farmers->getDataValidation('J2')->getFormula1());
    }
}

<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ImportFlowTest extends IntegrationTestCase
{
    private function partnerList(): UploadedFile
    {
        $book = new Spreadsheet();
        $book->getActiveSheet()->fromArray([
            ['S/N', 'Name of Farmer', 'Sex', 'Age', 'Phone No.', 'Type of ID', 'ID No.', 'District', 'Chiefdom', 'Village'],
            [1, 'Aminata Kamara', 'F', 34, 76123456, "Voter's card", '12-345-6789', 'Kailahun', 'Dea', 'Baiwalla'],
            [2, 'Mohamed Bangura', 'M', 41, '088 654 321', '', '', 'Bo', 'Kakua', 'Gerihun'],
            [3, 'AMINATA KAMARA', 'female', 34, '', '', '', 'kailahun district', 'Dea Chiefdom', 'baiwalla'],
            [4, 'Fatmata Sesay', 'F', 29, '076123456', 'NIN', 'SL998877', 'Kenama', 'Nongowa', 'Hangha'],
            [5, '', 'M', 50, '', '', '', 'Kono', '', 'Koidu'],
        ], null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'pod') . '.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, 'Kailahun and Bo farmers 2027.xlsx', null, null, true);
    }

    private function createProgramme(): string
    {
        $response = $this->asCompany()->postJson('int/v1/pod/programmes', [
            'name'          => 'Rice Seed and Fertiliser Support 2027',
            'operator_type' => 'ngo',
            'season'        => '2027 Main Season',
        ]);
        $response->assertCreated();
        $this->assertSame('SL', $response->json('programme.country'), 'Sierra Leone is the default country');

        return $response->json('programme.public_id');
    }

    public function testImportsAPartnerListFromUploadToCommit(): void
    {
        $programme = $this->createProgramme();

        // Upload: sheets, headers and a suggested mapping, nothing imported yet.
        $upload = $this->asCompany()->post("int/v1/pod/programmes/{$programme}/imports", ['file' => $this->partnerList()]);
        $upload->assertCreated();
        $this->assertSame(['Worksheet'], $upload->json('sheets'));
        $this->assertSame('Name of Farmer', $upload->json('suggested_mapping.full_name'));
        $this->assertFalse($upload->json('is_template'));
        $this->assertSame('****6789', $upload->json('sample_rows.2')['ID No.'], 'ID numbers masked in the sample');
        $import = $upload->json('batch.public_id');
        $this->assertSame(0, Beneficiary::count());

        // Preview with the suggested mapping.
        $preview = $this->asCompany()->postJson("int/v1/pod/imports/{$import}/preview");
        $preview->assertOk();
        $this->assertSame(['total' => 5, 'accepted' => 2, 'merged' => 1, 'exists' => 0, 'review' => 1, 'rejected' => 1], array_diff_key($preview->json('summary'), ['with_warnings' => 1]));
        $this->assertSame(0, Beneficiary::count(), 'preview creates nothing');

        // Committing with an undecided likely duplicate is refused.
        $this->asCompany()->postJson("int/v1/pod/imports/{$import}/commit")
            ->assertStatus(422)
            ->assertJsonFragment(['errors' => ['1 likely duplicates still need a decision.']]);

        // The review row: same phone as Aminata. A person decides they're different people.
        $review = $this->asCompany()->getJson("int/v1/pod/imports/{$import}/rows?status=review");
        $review->assertOk();
        $row = $review->json('data.0');
        $this->assertSame(5, $row['row_number']);
        $this->assertSame(['same_phone'], $row['review'][0]['reasons']);
        $this->assertSame('****8877', $row['raw']['id_number'], 'raw ID masked in the API');
        $this->assertArrayNotHasKey('id_hash', $row['data']);

        $this->asCompany()->postJson("int/v1/pod/imports/{$import}/rows/5/decision", ['decision' => 'import'])->assertOk();

        // The rejected row stays in the fix-up list with its reason.
        $rejected = $this->asCompany()->getJson("int/v1/pod/imports/{$import}/rows?status=rejected")->json('data.0');
        $this->assertSame('name_missing', $rejected['issues'][0]['code']);

        // Commit.
        $commit = $this->asCompany()->postJson("int/v1/pod/imports/{$import}/commit");
        $commit->assertOk();
        $this->assertSame(3, $commit->json('created'));
        $this->assertSame('committed', $commit->json('import.status'));

        $aminata = Beneficiary::where('name', 'Aminata Kamara')->firstOrFail();
        $this->assertSame('female', $aminata->sex);
        $this->assertSame('+23276123456', $aminata->phone);
        $this->assertSame('voter_id', $aminata->id_type);
        $this->assertSame('6789', $aminata->id_last4);
        $this->assertSame(['SL01', 'SL0101', 'SL010101'], [$aminata->admin_1, $aminata->admin_2, $aminata->admin_3]);
        $this->assertSame('unverified', $aminata->verification_status);
        $this->assertSame(['type' => 'excel', 'file' => 'Kailahun and Bo farmers 2027.xlsx', 'sheet' => 'Worksheet', 'row' => 2], array_intersect_key($aminata->source, array_flip(['type', 'file', 'sheet', 'row'])));
        $this->assertArrayNotHasKey('id_hash', $aminata->toArray());

        // The full ID number is nowhere in the stored beneficiaries.
        $this->assertStringNotContainsString('123456789', json_encode(Beneficiary::all()->makeVisible('id_hash')->toArray()));

        $fatmata = Beneficiary::where('name', 'Fatmata Sesay')->firstOrFail();
        $this->assertSame('SL0102', $fatmata->admin_2, 'Kenama matched to Kenema');

        // A committed import can't be previewed or committed again.
        $this->asCompany()->postJson("int/v1/pod/imports/{$import}/preview")->assertStatus(422);
        $this->asCompany()->postJson("int/v1/pod/imports/{$import}/commit")->assertStatus(422);
    }

    public function testImportingTheSameListAgainFindsThePeopleAlreadyRegistered(): void
    {
        $programme = $this->createProgramme();

        foreach ([1, 2] as $round) {
            $import = $this->asCompany()->post("int/v1/pod/programmes/{$programme}/imports", ['file' => $this->partnerList()])->json('batch.public_id');
            $summary = $this->asCompany()->postJson("int/v1/pod/imports/{$import}/preview")->json('summary');
            $this->asCompany()->postJson("int/v1/pod/imports/{$import}/commit", ['skip_undecided' => true])->assertOk();
        }

        // Round 2: Aminata, her repeated row and Mohamed are all already registered.
        // Fatmata wasn't imported in round 1 (undecided, skipped), so she's still a
        // likely duplicate of Aminata's phone.
        $this->assertSame(3, $summary['exists']);
        $this->assertSame(1, $summary['review']);
        $this->assertSame(0, $summary['accepted']);
        $this->assertSame(2, Beneficiary::count());
    }

    public function testTheTemplateDownloadsAndImportsWithoutMapping(): void
    {
        $programme = $this->createProgramme();

        $download = $this->asCompany()->get("int/v1/pod/programmes/{$programme}/farmer-list-template");
        $download->assertOk();
        $this->assertStringContainsString('Farmer List Template - Sierra Leone.xlsx', $download->headers->get('content-disposition'));

        $path = tempnam(sys_get_temp_dir(), 'pod') . '.xlsx';
        file_put_contents($path, $download->streamedContent());

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $book->getSheetByName('Farmers')->fromArray([['', 'Isatu Koroma', 'Female', 27, '', '', '', '', 'Southern', 'Bo', 'Kakua', '', 'Gerihun']], null, 'A2', true);
        (new Xlsx($book))->save($path);

        $upload = $this->asCompany()->post("int/v1/pod/programmes/{$programme}/imports", ['file' => new UploadedFile($path, 'filled-template.xlsx', null, null, true)]);
        $upload->assertCreated();
        $this->assertTrue($upload->json('is_template'));
        $this->assertSame(['Farmers', 'Lists', 'Instructions'], $upload->json('sheets'));

        $import = $upload->json('batch.public_id');
        $this->assertSame(1, $this->asCompany()->postJson("int/v1/pod/imports/{$import}/preview")->json('summary.accepted'));
    }

    public function testRefusesFilesThatAreNotSpreadsheets(): void
    {
        $programme = $this->createProgramme();
        $file      = UploadedFile::fake()->createWithContent('farmers.pdf', '%PDF-1.4');

        $this->asCompany()->post("int/v1/pod/programmes/{$programme}/imports", ['file' => $file])
            ->assertStatus(422)
            ->assertJsonFragment(['errors' => ['Only .xlsx, .xls and .csv files can be imported.']]);
    }

    public function testAnotherCompanyCannotSeeTheProgrammeOrItsImports(): void
    {
        $programme = $this->createProgramme();
        $import    = $this->asCompany()->post("int/v1/pod/programmes/{$programme}/imports", ['file' => $this->partnerList()])->json('batch.public_id');

        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/programmes/{$programme}")->assertNotFound();
        $this->asCompany(self::OTHER_COMPANY)->postJson("int/v1/pod/imports/{$import}/preview")->assertNotFound();
        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/imports/{$import}/rows")->assertNotFound();
        $this->assertSame(1, ImportBatch::count());
    }

    public function testRequiresSignIn(): void
    {
        $this->getJson('int/v1/pod/programmes')->assertUnauthorized();
    }
}

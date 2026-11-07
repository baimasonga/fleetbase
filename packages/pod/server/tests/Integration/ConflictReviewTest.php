<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Discrepancy;
use Fleetbase\Pod\Models\SyncConflict;
use Illuminate\Http\UploadedFile;

/**
 * Supervisor review of records made offline that the server couldn't accept.
 */
final class ConflictReviewTest extends IntegrationTestCase
{
    public const SUPERVISOR = '55555555-5555-5555-5555-555555555555';

    private string $event;

    private Beneficiary $aminata;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);

        $programme = $this->asCompany()->postJson('int/v1/pod/programmes', ['name' => 'Rice Support', 'operator_type' => 'ngo'])->json('programme.public_id');
        $this->asCompany()->putJson("int/v1/pod/programmes/{$programme}/items", ['items' => [
            ['sku' => 'RICE-25', 'name' => 'Rice seed, 25 kg', 'category' => 'seed', 'unit' => 'bag'],
            ['sku' => 'NPK-50', 'name' => 'NPK, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag'],
        ]])->assertOk();
        $this->asCompany()->putJson("int/v1/pod/programmes/{$programme}/entitlement-rules", ['rules' => [['sku' => 'RICE-25', 'quantity' => 1], ['sku' => 'NPK-50', 'quantity' => 2]]])->assertOk();
        $dp = $this->asCompany()->postJson('int/v1/pod/sites', ['name' => 'Baiwalla DP', 'type' => 'distribution_point'])->json('site.public_id');
        $this->asCompany()->postJson("int/v1/pod/sites/{$dp}/stock/receive", ['programme' => $programme, 'reference' => 'GRN', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]]])->assertCreated();

        $model         = \Fleetbase\Pod\Models\Programme::where('public_id', $programme)->first();
        $this->aminata = Beneficiary::create(['company_uuid' => self::COMPANY, 'programme_uuid' => $model->uuid, 'name' => 'Aminata Kamara', 'sex' => 'female', 'village' => 'Baiwalla', 'admin_3' => 'SL010101']);
        $this->aminata->card_code = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$this->aminata->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->json('card_code');
        $this->asCompany()->postJson("int/v1/pod/programmes/{$programme}/entitlements/generate")->assertOk();

        $this->event = $this->asCompany()->postJson("int/v1/pod/programmes/{$programme}/events", ['kind' => 'point', 'site' => $dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/assign", ['admin_3' => 'SL010101'])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();

        // Two phones serve Aminata offline; the second becomes a conflict. The officer is STOREKEEPER.
        $record = fn (string $id, int $minutes) => ['kind' => 'handover', 'event' => $this->event, 'client_handover_id' => $id, 'captured_at' => now()->subMinutes($minutes)->toIso8601String(),
            'card_code' => $this->aminata->card_code, 'identity' => ['face_matched' => true],
            'lines'     => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2]],
            'evidence'  => [['type' => 'photo', 'media' => "{$id}-p", 'sha256' => hash('sha256', $this->jpeg())], ['type' => 'mark']]];
        $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => [$record('p1-0001', 30), $record('p2-0001', 20)]])->assertOk();
        $this->asCompany()->post('int/v1/pod/field/media', ['file' => UploadedFile::fake()->createWithContent('p.jpg', $this->jpeg()), 'client_media_id' => 'p2-0001-p', 'sha256' => hash('sha256', $this->jpeg())])->assertCreated();
    }

    private function asSupervisor(string $company = self::COMPANY): static
    {
        return $this->asCompany($company, self::SUPERVISOR);
    }

    private function conflictId(): string
    {
        return $this->asSupervisor()->getJson('int/v1/pod/conflicts')->json('conflicts.0.id');
    }

    public function testListsOpenConflictsWithTheFarmerAndDay(): void
    {
        $list = $this->asSupervisor()->getJson('int/v1/pod/conflicts')->json('conflicts');

        $this->assertCount(1, $list);
        $this->assertSame(['handover', 'already_collected', 'p2-0001'], [$list[0]['kind'], $list[0]['error'], $list[0]['record']]);
        $this->assertSame('Aminata Kamara', $list[0]['farmer']['name']);
        $this->assertSame('Baiwalla day 1', $list[0]['event']['name']);
    }

    public function testShowsTheRecordNextToWhatTheServerAlreadyHolds(): void
    {
        $detail = $this->asSupervisor()->getJson('int/v1/pod/conflicts/' . $this->conflictId())->json('conflict');

        $this->assertSame([['sku' => 'RICE-25', 'name' => 'Rice seed, 25 kg', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'NPK-50', 'name' => 'NPK, 50 kg', 'lot' => 'L1', 'quantity' => 2]], $detail['lines']);
        $this->assertSame('p1-0001', $detail['existing']['handovers'][0]['record'], 'the handover it clashed with');
        $this->assertSame([true, false], array_column($detail['evidence'], 'uploaded'));
        $this->assertSame(['written_off', 'recovered', 'reviewed'], $detail['options']);

        // The photo can be viewed; another company's supervisor can't see any of it.
        $photo = $this->asSupervisor()->get('int/v1/pod/media/p2-0001-p');
        $photo->assertOk();
        $this->assertSame('image/jpeg', $photo->headers->get('content-type'));
        $this->asSupervisor(self::OTHER_COMPANY)->get('int/v1/pod/media/p2-0001-p')->assertNotFound();
        $id = $this->conflictId(); // before switching company: it signs in as the first company
        $this->asSupervisor(self::OTHER_COMPANY)->getJson("int/v1/pod/conflicts/{$id}")->assertNotFound();
        $this->assertSame([], $this->asSupervisor(self::OTHER_COMPANY)->getJson('int/v1/pod/conflicts')->json('conflicts'));
    }

    public function testWritingOffRecordsTheGoodsAsMissingStock(): void
    {
        $id = $this->conflictId();

        // The officer whose record it is can't resolve it, and a reason is required.
        $this->asCompany()->postJson("int/v1/pod/conflicts/{$id}/resolve", ['resolution' => 'written_off', 'notes' => 'Given twice by mistake, not recovered.'])
            ->assertStatus(422)->assertJsonFragment(['errors' => ['The person whose record this is can\'t resolve it. Ask another supervisor.']]);
        $this->asSupervisor()->postJson("int/v1/pod/conflicts/{$id}/resolve", ['resolution' => 'written_off', 'notes' => 'ok'])->assertStatus(422);

        $resolved = $this->asSupervisor()->postJson("int/v1/pod/conflicts/{$id}/resolve", ['resolution' => 'written_off', 'notes' => 'Given twice by mistake; the farmer had left before it was noticed.']);
        $resolved->assertOk();
        $this->assertSame(['resolved', 'written_off'], [$resolved->json('conflict.status'), $resolved->json('conflict.resolution')]);
        $this->assertSame([], $resolved->json('conflict.options'));

        $discrepancies = Discrepancy::orderBy('sku')->get();
        $this->assertSame([['NPK-50', 2.0, 'officer'], ['RICE-25', 1.0, 'officer']], $discrepancies->map(fn ($d) => [$d->sku, $d->quantity, $d->liable_party])->all());
        $this->assertStringContainsString('Sync conflict p2-0001', $discrepancies[0]->notes);
        $this->assertCount(2, SyncConflict::first()->discrepancies);

        // The day now closes with the goods accounted for: 10-1-1 rice, 20-2-2 NPK.
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/close", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 8], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 16]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])
            ->assertOk()->assertJson(['event' => ['status' => 'closed']]);

        $this->asSupervisor()->postJson("int/v1/pod/conflicts/{$id}/resolve", ['resolution' => 'reviewed', 'notes' => 'Again, should be refused.'])->assertStatus(422);
        $this->assertSame([], $this->asSupervisor()->getJson('int/v1/pod/conflicts')->json('conflicts'));
        $this->assertCount(1, $this->asSupervisor()->getJson('int/v1/pod/conflicts?status=resolved')->json('conflicts'));
    }

    public function testOnceTheDayIsClosedTheLossCantBeWrittenOffHere(): void
    {
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/discrepancies", ['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1, 'reason' => 'other', 'notes' => 'Given twice, see conflict p2-0001.'])->assertCreated();
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/discrepancies", ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2, 'reason' => 'other', 'notes' => 'Given twice, see conflict p2-0001.'])->assertCreated();
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/close", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 8], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 16]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();

        $id = $this->conflictId();
        $this->assertSame(['recovered', 'reviewed'], $this->asSupervisor()->getJson("int/v1/pod/conflicts/{$id}")->json('conflict.options'));
        $this->asSupervisor()->postJson("int/v1/pod/conflicts/{$id}/resolve", ['resolution' => 'written_off', 'notes' => 'Trying to write it off after close.'])
            ->assertStatus(422)->assertJsonFragment(['errors' => ['The day is no longer open, so the loss can\'t be recorded here: it belongs in the closing reconciliation. Choose "recovered" or "reviewed".']]);
        $this->asSupervisor()->postJson("int/v1/pod/conflicts/{$id}/resolve", ['resolution' => 'reviewed', 'notes' => 'Loss already recorded at closing by the officer.'])->assertOk();
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }
}

<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Models\SyncConflict;
use Fleetbase\Pod\Support\Cards\ProgrammeCard;
use Illuminate\Http\UploadedFile;

/**
 * The server side of the offline field app: bundle, batch sync, media.
 */
final class FieldSyncTest extends IntegrationTestCase
{
    private Programme $programme;

    private string $event;

    private array $farmers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);
        $this->artisan('pod:seed-admin-areas')->assertSuccessful();

        $id              = $this->asCompany()->postJson('int/v1/pod/programmes', ['name' => 'Rice Support', 'operator_type' => 'ngo'])->json('programme.public_id');
        $this->programme = Programme::where('public_id', $id)->firstOrFail();
        $this->asCompany()->putJson("int/v1/pod/programmes/{$id}/items", ['items' => [
            ['sku' => 'RICE-25', 'name' => 'Rice seed, 25 kg', 'category' => 'seed', 'unit' => 'bag'],
            ['sku' => 'NPK-50', 'name' => 'NPK, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag'],
        ]])->assertOk();
        $this->asCompany()->putJson("int/v1/pod/programmes/{$id}/entitlement-rules", ['rules' => [['sku' => 'RICE-25', 'quantity' => 1], ['sku' => 'NPK-50', 'quantity' => 2]]])->assertOk();

        $dp = $this->asCompany()->postJson('int/v1/pod/sites', ['name' => 'Baiwalla DP', 'type' => 'distribution_point', 'latitude' => 7.9804, 'longitude' => -10.6360])->json('site.public_id');
        $this->asCompany()->postJson("int/v1/pod/sites/{$dp}/stock/receive", ['programme' => $id, 'reference' => 'GRN-1', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]]])->assertCreated();

        foreach (['Aminata Kamara', 'Isatu Koroma'] as $name) {
            $farmer = Beneficiary::create(['company_uuid' => self::COMPANY, 'programme_uuid' => $this->programme->uuid, 'name' => $name, 'sex' => 'female', 'birth_year' => 1990, 'admin_3' => 'SL010101', 'village' => 'Baiwalla', 'id_type' => 'voter_id', 'id_last4' => '6789']);
            $farmer->card_code = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->json('card_code');
            $this->farmers[$name] = $farmer;
        }
        $this->asCompany()->postJson("int/v1/pod/programmes/{$id}/entitlements/generate")->assertOk();

        $this->event = $this->asCompany()->postJson("int/v1/pod/programmes/{$id}/events", ['kind' => 'point', 'site' => $dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/assign", ['admin_3' => 'SL010101'])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();
    }

    private function record(string $clientId, Beneficiary $farmer, array $overrides = []): array
    {
        return array_replace([
            'kind'               => 'handover',
            'event'              => $this->event,
            'client_handover_id' => $clientId,
            'card_code'          => $farmer->card_code,
            'identity'           => ['face_matched' => true],
            'lines'              => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2]],
            'evidence'           => [['type' => 'photo', 'media' => $clientId . '-p1', 'sha256' => str_repeat('a', 64)], ['type' => 'mark', 'media' => $clientId . '-m1', 'sha256' => str_repeat('b', 64)]],
            'latitude'           => 7.9805,
            'longitude'          => -10.6361,
            'captured_at'        => now()->subMinutes(30)->toIso8601String(),
        ], $overrides);
    }

    public function testTheBundleHasEverythingNeededOffline(): void
    {
        $bundle = $this->asCompany()->getJson("int/v1/pod/events/{$this->event}/bundle");
        $bundle->assertOk();

        $this->assertSame('Baiwalla day 1', $bundle->json('event.name'));
        $this->assertSame(500, $bundle->json('site.geofence_radius_m'));
        $this->assertCount(2, $bundle->json('items'));
        $this->assertCount(2, $bundle->json('beneficiaries'));
        $this->assertEquals([['sku' => 'NPK-50', 'lot' => 'L1', 'expiry' => null, 'quantity' => 20], ['sku' => 'RICE-25', 'lot' => 'R7', 'expiry' => null, 'quantity' => 10]], $bundle->json('stock'));
        $this->assertNotEmpty($bundle->json('programme.handover_policy.all'));

        // The card key checks cards offline exactly as the server does.
        $key     = base64_decode($bundle->json('card_key'));
        $aminata = $this->farmers['Aminata Kamara'];
        $this->assertTrue(ProgrammeCard::verify($aminata->card_code, $key, $this->programme->public_id)->valid);

        // ID last 4 travel only as a hash.
        $row = collect($bundle->json('beneficiaries'))->firstWhere('id', $aminata->public_id);
        $this->assertSame(hash('sha256', $aminata->public_id . '|6789'), $row['id_last4_hash']);
        $this->assertStringNotContainsString('6789', json_encode($bundle->json('beneficiaries')));
        $this->assertStringNotContainsString('id_hash', $bundle->getContent());

        // The same data gives the same version, so phones can tell when to refresh.
        $this->assertSame($bundle->json('version'), $this->asCompany()->getJson("int/v1/pod/events/{$this->event}/bundle")->json('version'));
        $this->assertSame([$this->event], array_column($this->asCompany()->getJson('int/v1/pod/field/events')->json('events'), 'id'));
    }

    public function testABatchFromTheFieldIsRecordedOnceInCaptureOrder(): void
    {
        $aminata = $this->farmers['Aminata Kamara'];
        $isatu   = $this->farmers['Isatu Koroma'];

        $batch = ['items' => [
            $this->record('phone1-0002', $isatu, ['captured_at' => now()->subMinutes(10)->toIso8601String()]),
            $this->record('phone1-0001', $aminata, ['captured_at' => now()->subMinutes(20)->toIso8601String()]),
        ]];

        $first = $this->asCompany()->postJson('int/v1/pod/field/sync', $batch);
        $first->assertOk();
        $this->assertSame(['phone1-0001', 'phone1-0002'], array_column($first->json('results'), 'client_handover_id'), 'processed in capture order');
        $this->assertSame(['accepted', 'accepted'], array_column($first->json('results'), 'status'));

        // The phone didn't get the answer (lost signal) and sends the same batch again.
        $again = $this->asCompany()->postJson('int/v1/pod/field/sync', $batch);
        $this->assertSame(['replayed', 'replayed'], array_column($again->json('results'), 'status'));
        $this->assertSame(2, Handover::count());

        // Stock moved once.
        $this->assertEquals([['sku' => 'NPK-50', 'lot' => 'L1', 'expiry' => null, 'quantity' => 16], ['sku' => 'RICE-25', 'lot' => 'R7', 'expiry' => null, 'quantity' => 8]], $this->asCompany()->getJson("int/v1/pod/events/{$this->event}")->json('stock'));
    }

    public function testAFarmerServedOnTwoPhonesOfflineBecomesAConflictNotALoss(): void
    {
        $aminata = $this->farmers['Aminata Kamara'];

        $results = $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => [
            $this->record('phone1-0001', $aminata, ['captured_at' => now()->subMinutes(20)->toIso8601String()]),
            $this->record('phone2-0001', $aminata, ['captured_at' => now()->subMinutes(15)->toIso8601String()]),
        ]])->json('results');

        $this->assertSame('accepted', $results[0]['status']);
        $this->assertSame(['conflict', 'already_collected'], [$results[1]['status'], $results[1]['error']]);

        $conflict = SyncConflict::firstOrFail();
        $this->assertSame('phone2-0001', $conflict->client_handover_id);
        $this->assertSame('handover', $conflict->kind);
        $this->assertSame($aminata->card_code, $conflict->payload['card_code']);

        // Asking again gives the same answer; the conflict isn't duplicated.
        $this->assertSame('conflict', $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => [$this->record('phone2-0001', $aminata)]])->json('results.0.status'));
        $this->assertSame(1, SyncConflict::count());
        $this->assertCount(1, $this->asCompany()->getJson("int/v1/pod/events/{$this->event}/conflicts")->json('conflicts'));
    }

    public function testLateAndFutureCaptureTimesAreFlagged(): void
    {
        $results = $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => [
            $this->record('phone1-0001', $this->farmers['Aminata Kamara'], ['captured_at' => now()->subHours(30)->toIso8601String()]),
            $this->record('phone1-0002', $this->farmers['Isatu Koroma'], ['captured_at' => now()->addHours(2)->toIso8601String()]),
        ]])->json('results');

        $this->assertContains('late_sync', $results[0]['flags']);
        $this->assertContains('clock_ahead', $results[1]['flags']);
    }

    public function testInvalidRecordsAreReportedPerItem(): void
    {
        $results = $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => [
            ['kind' => 'handover', 'event' => $this->event],                                       // no client id
            $this->record('phone1-0009', $this->farmers['Aminata Kamara'], ['event' => 'event_gone']), // unknown event
            $this->record('phone1-0010', $this->farmers['Aminata Kamara'], ['lines' => []]),        // invalid
        ]])->json('results');

        $this->assertSame(['invalid', 'conflict', 'conflict'], array_column($results, 'status'));
        $this->assertSame(['invalid', 'event_not_found', 'invalid'], array_column($results, 'error'));
    }

    public function testPhotosUploadedLaterMustMatchTheHashTheRecordCommittedTo(): void
    {
        $jpeg = $this->jpeg();
        $sha  = hash('sha256', $jpeg);

        $record = $this->record('phone1-0001', $this->farmers['Aminata Kamara'], ['evidence' => [['type' => 'photo', 'media' => 'phone1-p0001', 'sha256' => $sha], ['type' => 'mark', 'media' => 'phone1-m0001', 'sha256' => str_repeat('c', 64)]]]);
        $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => [$record]])->assertOk();
        $this->assertSame(['expected' => 2, 'uploaded' => 0, 'missing' => ['phone1-p0001', 'phone1-m0001']], $this->asCompany()->getJson("int/v1/pod/events/{$this->event}/media-status")->json());

        $upload = fn (string $content, string $hash, string $id = 'phone1-p0001') => $this->asCompany()->post('int/v1/pod/field/media', [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', $content), 'client_media_id' => $id, 'sha256' => $hash, 'kind' => 'photo',
        ]);

        // Damaged in transit: refused, the phone keeps it and tries again.
        $upload(substr($jpeg, 0, -10), $sha)->assertStatus(422);
        $upload($jpeg, $sha)->assertCreated();
        $upload($jpeg, $sha)->assertOk()->assertJson(['replayed' => true]);
        $upload($this->jpeg(2), hash('sha256', $this->jpeg(2)))->assertStatus(409);
        $upload('not an image', hash('sha256', 'not an image'), 'phone1-x')->assertStatus(422);

        $this->assertSame(['expected' => 2, 'uploaded' => 1, 'missing' => ['phone1-m0001']], $this->asCompany()->getJson("int/v1/pod/events/{$this->event}/media-status")->json());
    }

    public function testAnotherCompanyGetsNoBundleAndCannotSyncIntoTheEvent(): void
    {
        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/events/{$this->event}/bundle")->assertNotFound();

        $result = $this->asCompany(self::OTHER_COMPANY)->postJson('int/v1/pod/field/sync', ['items' => [$this->record('x-1', $this->farmers['Aminata Kamara'])]])->json('results.0');
        $this->assertSame('event_not_found', $result['error']);
        $this->assertSame(0, Handover::count());
    }

    public function testServesTheFieldAppButNothingOutsideIt(): void
    {
        $this->get('pod-field/')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->get('pod-field/index.html')->assertOk();
        $this->get('pod-field/../composer.json')->assertNotFound();
        $this->get('pod-field/%2e%2e/composer.json')->assertNotFound();
        $this->get('pod-field/nope.js')->assertNotFound();
    }

    private function jpeg(int $shade = 1): string
    {
        $image = imagecreatetruecolor(16, 16);
        imagefill($image, 0, 0, imagecolorallocate($image, 40 * $shade, 120, 60));
        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }
}

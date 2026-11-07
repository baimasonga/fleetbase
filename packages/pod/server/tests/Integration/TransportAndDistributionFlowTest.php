<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Models\StockMovement;

/**
 * One shipment from the supplier to farmers: central warehouse -> truck ->
 * distribution point -> distribution day -> farmers, and a door-to-door route.
 */
final class TransportAndDistributionFlowTest extends IntegrationTestCase
{
    private Programme $programme;

    private string $central;

    private string $dp;

    private string $van;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);
        $this->artisan('pod:seed-admin-areas')->assertSuccessful();

        $id              = $this->asCompany()->postJson('int/v1/pod/programmes', ['name' => 'Rice Support', 'operator_type' => 'ngo', 'season' => '2027 Main Season'])->json('programme.public_id');
        $this->programme = Programme::where('public_id', $id)->firstOrFail();

        $this->asCompany()->putJson($this->url('/items'), ['items' => [
            ['sku' => 'RICE-25', 'name' => 'Rice seed, 25 kg', 'category' => 'seed', 'unit' => 'bag'],
            ['sku' => 'NPK-50', 'name' => 'NPK 15-15-15, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag'],
            ['sku' => 'HERB-1L', 'name' => 'Herbicide, 1 litre', 'category' => 'pesticide', 'unit' => 'bottle'],
        ]])->assertOk();
        $this->asCompany()->putJson($this->url('/entitlement-rules'), ['rules' => [
            ['sku' => 'RICE-25', 'quantity' => 1],
            ['sku' => 'NPK-50', 'quantity' => 2],
            ['sku' => 'HERB-1L', 'quantity' => 1],
        ]])->assertOk();

        $this->central = $this->site(['name' => 'Kenema Central Warehouse', 'type' => 'central_warehouse', 'admin_2' => 'SL0102']);
        // Baiwalla, Dea chiefdom, Kailahun: about 7.98 N, 10.64 W.
        $this->dp  = $this->site(['name' => 'Baiwalla Distribution Point', 'type' => 'distribution_point', 'admin_2' => 'SL0101', 'admin_3' => 'SL010101', 'village' => 'Baiwalla', 'latitude' => 7.9804, 'longitude' => -10.6360]);
        $this->van = $this->site(['name' => 'Van AKV 123', 'type' => 'vehicle']);

        $this->asCompany()->postJson("int/v1/pod/sites/{$this->central}/stock/receive", [
            'programme' => $this->programme->public_id,
            'reference' => 'GRN-2027-001',
            'lines'     => [
                ['sku' => 'RICE-25', 'lot' => 'R7', 'expiry' => '2028-06-30', 'quantity' => 200],
                ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 500],
                ['sku' => 'HERB-1L', 'lot' => 'H3', 'expiry' => '2028-01-31', 'quantity' => 50],
            ],
        ])->assertCreated();
    }

    private function setRule(string $key, mixed $value): void
    {
        $this->programme->refresh();
        $this->programme->update(['rules' => array_merge($this->programme->rules ?? [], [$key => $value])]);
    }

    private function url(string $path): string
    {
        return 'int/v1/pod/programmes/' . $this->programme->public_id . $path;
    }

    private function site(array $data): string
    {
        return $this->asCompany()->postJson('int/v1/pod/sites', $data)->assertCreated()->json('site.public_id');
    }

    private function stockAt(string $site): array
    {
        $out = [];
        foreach ($this->asCompany()->getJson("int/v1/pod/sites/{$site}")->json('stock') as $line) {
            $out[$line['sku'] . '/' . $line['lot']] = (float) $line['quantity'];
        }

        return $out;
    }

    /**
     * A trip that arrives exactly as loaded, to stock the distribution point.
     */
    private function deliverToDp(array $lines): void
    {
        $leg = $this->asCompany()->postJson($this->url('/legs'), ['origin' => $this->central, 'destination' => $this->dp, 'transporter_kind' => 'staff', 'vehicle_plate' => 'akv 123', 'waybill_no' => 'WB-1'])->json('leg.public_id');
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => $lines, 'seals' => ['S-1'], 'restricted_segregated' => true])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/depart")->assertOk();
        $this->asCompany(self::COMPANY, self::RECEIVER)->postJson("int/v1/pod/legs/{$leg}/receive", [
            'lines' => array_map(fn ($l) => ['sku' => $l['sku'], 'lot' => $l['lot'], 'good' => $l['quantity']], $lines),
            'seals' => [['number' => 'S-1', 'intact' => true]],
        ])->assertOk();
        $this->asCompany(self::COMPANY, self::RECEIVER)->postJson("int/v1/pod/legs/{$leg}/close")->assertOk();
    }

    private function farmer(string $name, array $attributes = []): Beneficiary
    {
        $farmer = Beneficiary::create($attributes + [
            'company_uuid' => self::COMPANY, 'programme_uuid' => $this->programme->uuid, 'name' => $name, 'sex' => 'female', 'birth_year' => 1990,
            'admin_1' => 'SL01', 'admin_2' => 'SL0101', 'admin_3' => 'SL010101', 'village' => 'Baiwalla', 'id_type' => 'voter_id', 'id_last4' => '6789',
        ]);
        $farmer->card_code = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->assertOk()->json('card_code');

        return $farmer;
    }

    private function openDay(array $stock): string
    {
        $event = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'point', 'site' => $this->dp, 'name' => 'Baiwalla day 1', 'scheduled_date' => '2027-03-12'])->assertCreated()->json('event.public_id');
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/assign", ['admin_3' => 'SL010101'])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/open", ['stock' => $stock, 'witnesses' => [['name' => 'Chief Musa Kallon', 'role' => 'chief']]])->assertOk();

        return $event;
    }

    private function handover(string $event, Beneficiary $farmer, array $overrides = [])
    {
        return $this->asCompany()->postJson("int/v1/pod/events/{$event}/handovers", array_replace([
            'card_code' => $farmer->card_code,
            'identity'  => ['face_matched' => true],
            'lines'     => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2]],
            'evidence'  => [['type' => 'photo', 'file' => 'file_1'], ['type' => 'mark']],
            'latitude'  => 7.9806,
            'longitude' => -10.6362,
        ], $overrides));
    }

    // --- Truck trips ---------------------------------------------------------------------

    public function testATripWithAShortageAndABrokenSealMustBeExplainedBeforeClosing(): void
    {
        $leg = $this->asCompany()->postJson($this->url('/legs'), [
            'origin' => $this->central, 'destination' => $this->dp, 'transporter_kind' => 'hired', 'transporter_name' => 'Sesay Transport',
            'vehicle_plate' => 'akv 123', 'waybill_no' => 'WB-2027-014', 'expected_duration_min' => 240,
        ])->assertCreated()->json('leg.public_id');

        // Pesticide with seed and fertiliser: segregation must be confirmed.
        $lines = [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 40], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 100], ['sku' => 'HERB-1L', 'lot' => 'H3', 'quantity' => 10]];
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => $lines, 'seals' => ['SL-00123', 'SL-00124']])
            ->assertStatus(422)->assertJsonFragment(['errors' => ['Pesticides and veterinary drugs are on this truck with other inputs. Confirm they are kept in a separate compartment or clearly apart.']]);

        // More than the warehouse holds is refused, and nothing moves.
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 501]], 'seals' => []])
            ->assertStatus(422)->assertJsonFragment(['errors' => ['Not enough NPK-50 lot L1: 500 on hand, 501 needed.']]);
        $this->assertSame(500.0, $this->stockAt($this->central)['NPK-50/L1']);

        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => $lines, 'seals' => ['SL-00123', 'SL-00124'], 'restricted_segregated' => true])->assertOk();
        $this->assertSame(400.0, $this->stockAt($this->central)['NPK-50/L1']);
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/depart")->assertOk()->assertJson(['leg' => ['status' => 'in_transit', 'vehicle_plate' => 'AKV 123']]);
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/arrive")->assertOk();

        $receipt = [
            'lines' => [
                ['sku' => 'RICE-25', 'lot' => 'R7', 'good' => 40],
                ['sku' => 'NPK-50', 'lot' => 'L1', 'good' => 95, 'damaged' => 2, 'note' => 'Two bags torn and wet'],
                ['sku' => 'HERB-1L', 'lot' => 'H3', 'good' => 10],
            ],
            'seals' => [['number' => 'sl 00123', 'intact' => true], ['number' => 'SL-00124', 'intact' => false]],
        ];

        // The storekeeper who loaded the truck can't receive it.
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/receive", $receipt)
            ->assertStatus(422)->assertJsonFragment(['errors' => ["The person who loaded this trip can't receive it. Another staff member must do it."]]);

        $received = $this->asCompany(self::COMPANY, self::RECEIVER)->postJson("int/v1/pod/legs/{$leg}/receive", $receipt);
        $received->assertOk();
        $this->assertSame('broken', $received->json('leg.seal_status'));
        $this->assertContains('seal_broken', $received->json('leg.flags'));

        $discrepancies = collect($received->json('leg.discrepancies'))->keyBy('reason');
        $this->assertSame(2.0, (float) $discrepancies['damaged']['quantity']);
        $this->assertSame(3.0, (float) $discrepancies['short_delivered']['quantity']);
        $this->assertSame('transporter', $discrepancies['short_delivered']['liable_party'], 'a hired transporter is liable while investigated');

        // Only good stock enters the distribution point.
        $this->assertSame(['HERB-1L/H3' => 10.0, 'NPK-50/L1' => 95.0, 'RICE-25/R7' => 40.0], $this->stockAt($this->dp));

        // Closing waits for every discrepancy and the seal.
        $close = fn (array $body = []) => $this->asCompany(self::COMPANY, self::RECEIVER)->postJson("int/v1/pod/legs/{$leg}/close", $body);
        $close()->assertStatus(422)->assertJsonFragment(['errors' => ['2 discrepancies on this trip still need an explanation and a resolution.']]);

        foreach ($discrepancies as $d) {
            $this->asCompany(self::COMPANY, self::RECEIVER)->postJson("int/v1/pod/discrepancies/{$d['public_id']}/resolve", ['notes' => 'Driver statement taken; amount deducted from transport invoice.', 'resolution' => 'recovered'])->assertOk();
        }
        $close()->assertStatus(422)->assertJsonFragment(['errors' => ['The seals were broken on arrival. Explain what happened before closing the trip.']]);

        $closed = $close(['seal_explanation' => 'Seal SL-00124 cut at a police checkpoint; officer name recorded.']);
        $closed->assertOk();
        $this->assertSame('closed', $closed->json('leg.status'));
        $this->assertSame(64, strlen($closed->json('leg.seal_hash')));
    }

    public function testCancellingALoadedTripReturnsTheStock(): void
    {
        $leg = $this->asCompany()->postJson($this->url('/legs'), ['origin' => $this->central, 'destination' => $this->dp, 'waybill_no' => 'WB-9'])->json('leg.public_id');
        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 50]], 'seals' => []])->assertOk();
        $this->assertSame(450.0, $this->stockAt($this->central)['NPK-50/L1']);

        $this->asCompany()->postJson("int/v1/pod/legs/{$leg}/cancel")->assertOk()->assertJson(['leg' => ['status' => 'cancelled']]);
        $this->assertSame(500.0, $this->stockAt($this->central)['NPK-50/L1']);
    }

    public function testStockMovementsCannotBeEdited(): void
    {
        $this->expectException(\LogicException::class);
        StockMovement::firstOrFail()->update(['quantity' => 1000]);
    }

    // --- Distribution day ----------------------------------------------------------------

    public function testADistributionDayFromOpeningToSealedReport(): void
    {
        $this->deliverToDp([['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20], ['sku' => 'HERB-1L', 'lot' => 'H3', 'quantity' => 5]]);
        $aminata = $this->farmer('Aminata Kamara');
        $isatu   = $this->farmer('Isatu Koroma', ['birth_year' => 2001, 'trained_restricted_products' => '2026-02-01']);
        $musa    = $this->farmer('Musa Bangura', ['sex' => 'male', 'birth_year' => 1962]);

        // Opening takes more than the site holds: refused.
        $event = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'point', 'site' => $this->dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();
        $assign = $this->asCompany()->postJson("int/v1/pod/events/{$event}/assign", ['admin_3' => 'SL010101']);
        $this->assertSame(['assigned' => 7, 'beneficiaries' => 3, 'elsewhere' => 0], $assign->json(), 'herbicide only for trained Isatu');

        $this->asCompany()->postJson("int/v1/pod/events/{$event}/open", ['stock' => [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 21]], 'witnesses' => [['name' => 'Chief Musa Kallon', 'role' => 'chief']]])
            ->assertStatus(422);
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/open", ['stock' => [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2]], 'witnesses' => []])
            ->assertStatus(422);
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/open", [
            'stock'     => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20], ['sku' => 'HERB-1L', 'lot' => 'H3', 'quantity' => 5]],
            'witnesses' => [['name' => 'Chief Musa Kallon', 'role' => 'chief'], ['name' => 'Fatu Lahai', 'role' => 'extension_officer']],
        ])->assertOk()->assertJson(['event' => ['status' => 'open']]);
        $this->assertSame([], $this->stockAt($this->dp), 'stock moved to the event');

        // Aminata: card + face (L2), full package.
        $first = $this->handover($event, $aminata, ['client_handover_id' => 'dev1-0001']);
        $first->assertCreated();
        $this->assertSame('delivered', $first->json('handover.outcome'));
        $this->assertSame('L2', $first->json('handover.identity_level'));
        $this->assertSame([], $first->json('handover.flags'), 'within 500 m of the distribution point');
        $this->assertSame(64, strlen($first->json('handover.seal_hash')));

        // The phone retries the same handover (lost connection): no double issue.
        $this->handover($event, $aminata, ['client_handover_id' => 'dev1-0001'])->assertOk()->assertJson(['replayed' => true]);
        // A second, new handover to Aminata: she has collected everything.
        $this->handover($event, $aminata)->assertStatus(422)->assertJson(['error' => 'already_collected']);

        // Isatu: more than entitled is refused; the ID check makes it L3; pesticide needs a safety acknowledgement.
        $this->handover($event, $isatu, ['lines' => [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 3]]])
            ->assertStatus(422)->assertJson(['error' => 'over_entitlement', 'errors' => ['NPK-50: 2 bag left to collect, 3 asked for.']]);
        $noSafety = $this->handover($event, $isatu, ['identity' => ['face_matched' => true, 'id_last4' => '6789'], 'lines' => [['sku' => 'HERB-1L', 'lot' => 'H3', 'quantity' => 1]]]);
        $noSafety->assertStatus(422)->assertJson(['error' => 'proof_missing', 'missing' => [['type' => 'safety_ack']]]);
        $herbicide = $this->handover($event, $isatu, [
            'identity' => ['face_matched' => true, 'id_last4' => '6789'],
            'lines'    => [['sku' => 'HERB-1L', 'lot' => 'H3', 'quantity' => 1]],
            'evidence' => [['type' => 'photo'], ['type' => 'signature'], ['type' => 'safety_ack']],
        ]);
        $herbicide->assertCreated();
        $this->assertSame(['L3', 'partial'], [$herbicide->json('handover.identity_level'), $herbicide->json('handover.outcome')]);
        $this->assertEquals(['HERB-1L' => 0, 'NPK-50' => 2, 'RICE-25' => 1], collect($herbicide->json('remaining'))->pluck('remaining', 'sku')->sortKeys()->all());

        // Musa: no card with him. A photo and the chief's confirmation (L1), collected by his son as proxy.
        $proxy = $this->asCompany()->postJson("int/v1/pod/events/{$event}/handovers", [
            'beneficiary'    => $musa->public_id,
            'received_by'    => 'proxy',
            'recipient_name' => 'Abu Bangura',
            'identity'       => ['photo_taken' => true, 'witness_confirmed' => true],
            'lines'          => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]],
            'evidence'       => [['type' => 'photo'], ['type' => 'mark']],
            'latitude'       => 7.9900, 'longitude' => -10.6360, // about 1 km away
        ]);
        $proxy->assertStatus(422)->assertJson(['error' => 'proof_missing', 'missing' => [['type' => 'witness_signature']]]);
        $proxy = $this->asCompany()->postJson("int/v1/pod/events/{$event}/handovers", [
            'beneficiary'    => $musa->public_id,
            'received_by'    => 'proxy',
            'recipient_name' => 'Abu Bangura',
            'identity'       => ['photo_taken' => true, 'witness_confirmed' => true],
            'lines'          => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]],
            'evidence'       => [['type' => 'photo'], ['type' => 'mark'], ['type' => 'witness_signature', 'name' => 'Chief Musa Kallon']],
            'latitude'       => 7.9900, 'longitude' => -10.6360,
        ]);
        $proxy->assertCreated();
        $this->assertEqualsCanonicalizing(['outside_geofence', 'identity_l1', 'proxy'], $proxy->json('handover.flags'));

        // A sealed handover can't be edited.
        try {
            Handover::where('public_id', $first->json('handover.public_id'))->firstOrFail()->update(['lines' => []]);
            $this->fail('A sealed handover was changed.');
        } catch (\LogicException) {
        }

        // Close: handed out 2 rice (Aminata, Musa), 2 NPK (Aminata), 1 herbicide (Isatu).
        // The count finds 8 rice and 4 herbicide as expected, but 17 NPK instead of 18.
        $closing = [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 8], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 17], ['sku' => 'HERB-1L', 'lot' => 'H3', 'quantity' => 4]];
        $witness = [['name' => 'Chief Musa Kallon', 'role' => 'chief']];
        $unbalanced = $this->asCompany()->postJson("int/v1/pod/events/{$event}/close", ['stock' => $closing, 'witnesses' => $witness]);
        $unbalanced->assertStatus(422)->assertJson(['error' => 'unbalanced']);
        $this->assertEquals([['sku' => 'NPK-50', 'lot' => 'L1', 'variance' => 1]], array_map(fn ($l) => array_intersect_key($l, array_flip(['sku', 'lot', 'variance'])), $unbalanced->json('lines')));

        // One NPK bag was torn and spilled: record it, then the count balances.
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/discrepancies", ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 1, 'reason' => 'torn_bag', 'notes' => 'Bag torn while unloading; contents spilled.'])->assertCreated();
        $closed = $this->asCompany()->postJson("int/v1/pod/events/{$event}/close", ['stock' => $closing, 'witnesses' => $witness]);
        $closed->assertOk();
        $this->assertSame('closed', $closed->json('event.status'));
        $this->assertTrue($closed->json('event.reconciliation.balanced'));
        $this->assertSame(64, strlen($closed->json('event.seal_hash')));

        $totals = $closed->json('event.totals');
        $this->assertSame(3, $totals['served']['total']);
        $this->assertSame(['female' => 2, 'male' => 1, 'unknown' => 0], $totals['served']['by_sex']);
        $this->assertSame(1, $totals['served']['youth_15_35'], 'Isatu, born 2001');
        $this->assertSame(1, $totals['proxy']);
        $this->assertEquals(['HERB-1L' => 1, 'NPK-50' => 2, 'RICE-25' => 2], $totals['quantities']);

        // What's left goes back to the distribution point.
        $this->assertSame(['HERB-1L/H3' => 4.0, 'NPK-50/L1' => 17.0, 'RICE-25/R7' => 8.0], $this->stockAt($this->dp));

        // Entitlements reflect what was collected.
        $this->assertSame(Entitlement::COLLECTED, Entitlement::where('beneficiary_uuid', $aminata->uuid)->where('sku', 'NPK-50')->value('status'));
        $this->assertSame(Entitlement::COLLECTED, Entitlement::where('beneficiary_uuid', $isatu->uuid)->where('sku', 'HERB-1L')->value('status'));
        $this->assertSame(Entitlement::OPEN, Entitlement::where('beneficiary_uuid', $isatu->uuid)->where('sku', 'NPK-50')->value('status'), 'she can collect her NPK at another distribution');

        // The report, as JSON and PDF.
        $report = $this->asCompany()->getJson("int/v1/pod/events/{$event}/report");
        $report->assertOk();
        $this->assertCount(3, $report->json('handovers'));
        $pdf = $this->asCompany()->get("int/v1/pod/events/{$event}/report?format=pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        // Closed is final.
        $this->handover($event, $isatu, ['identity' => ['face_matched' => true], 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]]])->assertStatus(422);
    }

    public function testRevokedCardsExpiredAndRecalledLotsAreRefused(): void
    {
        $this->deliverToDp([['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 5], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 10]]);
        $aminata = $this->farmer('Aminata Kamara');
        $event   = $this->openDay([['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 5], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 10]]);

        // A replaced card is refused.
        $old = $aminata->card_code;
        $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$aminata->public_id}/card/reissue", ['reason' => 'lost'])->assertOk();
        $this->handover($event, $aminata, ['card_code' => $old])->assertStatus(422)->assertJson(['error' => 'card_revoked']);

        // A recalled lot is refused.
        $this->setRule('recalled_lots', [['sku' => 'NPK-50', 'lot' => 'L1']]);
        $aminata->card_code = $this->asCompany()->getJson("int/v1/pod/beneficiaries/{$aminata->public_id}/card")->json('card_code');
        $this->handover($event, $aminata)->assertStatus(422)->assertJson(['error' => 'lot_recalled']);

        // Unknown farmer, and no identity check at all.
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/handovers", ['beneficiary' => 'beneficiary_nobody', 'lines' => [['sku' => 'RICE-25', 'quantity' => 1]]])->assertStatus(422)->assertJson(['error' => 'not_found']);
        $this->handover($event, $aminata, ['identity' => [], 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]]])->assertStatus(422)->assertJson(['error' => 'identity_insufficient']);
    }

    public function testAnExpiredLotIsRefused(): void
    {
        $this->asCompany()->postJson("int/v1/pod/sites/{$this->dp}/stock/receive", ['programme' => $this->programme->public_id, 'reference' => 'GRN-OLD', 'lines' => [['sku' => 'RICE-25', 'lot' => 'OLD1', 'expiry' => '2020-01-31', 'quantity' => 3]]])->assertCreated();
        $aminata = $this->farmer('Aminata Kamara');
        $event   = $this->openDay([['sku' => 'RICE-25', 'lot' => 'OLD1', 'quantity' => 3]]);

        $this->handover($event, $aminata, ['lines' => [['sku' => 'RICE-25', 'lot' => 'OLD1', 'quantity' => 1]]])
            ->assertStatus(422)->assertJson(['error' => 'lot_expired', 'errors' => ['Lot OLD1 of RICE-25 expired on 2020-01-31. Do not hand it over.']]);
    }

    public function testAFarmerAssignedToAnotherDayIsSentThere(): void
    {
        $this->deliverToDp([['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 5]]);
        $aminata = $this->farmer('Aminata Kamara');
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();

        $other = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'point', 'site' => $this->dp, 'name' => 'Baiwalla day 2'])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$other}/assign", ['beneficiaries' => [$aminata->public_id]])->assertOk();

        $event = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'point', 'site' => $this->dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        // Rice and NPK; her herbicide entitlement isn't created because she has no safety training.
        $this->assertSame(['assigned' => 0, 'beneficiaries' => 0, 'elsewhere' => 2], $this->asCompany()->postJson("int/v1/pod/events/{$event}/assign", ['admin_3' => 'SL010101'])->json());
        $this->asCompany()->postJson("int/v1/pod/events/{$event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 5]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();

        $this->handover($event, $aminata, ['lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]]])->assertStatus(422)->assertJson(['error' => 'assigned_elsewhere']);
    }

    // --- Door-to-door --------------------------------------------------------------------

    public function testDoorToDoorFailedAttemptsThenReassignmentToADistributionPoint(): void
    {
        $this->asCompany()->postJson("int/v1/pod/sites/{$this->van}/stock/receive", ['programme' => $this->programme->public_id, 'reference' => 'Loaded at Kenema', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 2], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 4]]])->assertCreated();
        $this->setRule('delivery_model', 'door_to_door');
        $hawa = $this->farmer('Hawa Fofanah', ['latitude' => 7.9850, 'longitude' => -10.6400]);
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();

        $route = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'route', 'site' => $this->van, 'name' => 'Dea route 1'])->assertCreated()->json('event.public_id');
        $this->asCompany()->postJson($this->url('/events'), ['kind' => 'route', 'site' => $this->dp])->assertStatus(422);
        $this->asCompany()->postJson("int/v1/pod/events/{$route}/assign", ['beneficiaries' => [$hawa->public_id]])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$route}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 2], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 4]], 'witnesses' => [['name' => 'Fatu Lahai', 'role' => 'extension_officer']]])->assertOk();

        $attempt = fn (string $client) => $this->asCompany()->postJson("int/v1/pod/events/{$route}/attempts", ['client_handover_id' => $client, 'beneficiary' => $hawa->public_id, 'reason' => 'farmer_absent', 'latitude' => 7.9851, 'longitude' => -10.6401]);

        $first = $attempt('van-1');
        $first->assertCreated();
        $this->assertSame([1, 2, false], [$first->json('attempts'), $first->json('max_attempts'), $first->json('needs_reassignment')]);
        $this->assertSame([], $first->json('handover.flags'), 'at the household');
        $this->assertSame(1, $attempt('van-1')->json('attempts'), 'a retried attempt is not counted twice');

        $second = $attempt('van-2');
        $this->assertSame([2, true], [$second->json('attempts'), $second->json('needs_reassignment')]);

        // Move her to the distribution point day.
        $day = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'point', 'site' => $this->dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        $this->assertSame(2, $this->asCompany()->postJson("int/v1/pod/events/{$route}/reassign", ['beneficiary' => $hawa->public_id, 'to_event' => $day])->json('moved'));
        $this->assertSame(['distribution_point'], Entitlement::where('beneficiary_uuid', $hawa->uuid)->distinct()->pluck('delivery_model')->all());

        // The route closes with all its stock returned to the van.
        $this->asCompany()->postJson("int/v1/pod/events/{$route}/close", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 2], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 4]], 'witnesses' => [['name' => 'Fatu Lahai', 'role' => 'extension_officer']]])
            ->assertOk()->assertJson(['event' => ['totals' => ['failed_attempts' => 2, 'handovers' => 0]]]);
        $this->assertSame(['NPK-50/L1' => 4.0, 'RICE-25/R7' => 2.0], $this->stockAt($this->van));
    }

    public function testAMovedOrDeceasedFarmerGoesToRegistryReviewNotAnotherVisit(): void
    {
        $this->asCompany()->postJson("int/v1/pod/sites/{$this->van}/stock/receive", ['programme' => $this->programme->public_id, 'reference' => 'x', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]]])->assertCreated();
        $this->setRule('delivery_model', 'door_to_door');
        $farmer = $this->farmer('Moved Away');
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();
        $route = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'route', 'site' => $this->van])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$route}/assign", ['beneficiaries' => [$farmer->public_id]])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$route}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]], 'witnesses' => [['name' => 'X', 'role' => 'other']]])->assertOk();

        $result = $this->asCompany()->postJson("int/v1/pod/events/{$route}/attempts", ['beneficiary' => $farmer->public_id, 'reason' => 'household_moved']);

        $this->assertSame([true, false], [$result->json('registry_review'), $result->json('needs_reassignment')]);
        $this->assertContains('no_location', $result->json('handover.flags'));
        $this->assertSame('household_moved', Beneficiary::find($farmer->id)->meta['registry_review']);
    }

    public function testAnotherCompanyCannotTouchTripsOrDistributions(): void
    {
        $leg   = $this->asCompany()->postJson($this->url('/legs'), ['origin' => $this->central, 'destination' => $this->dp, 'waybill_no' => 'WB'])->json('leg.public_id');
        $event = $this->asCompany()->postJson($this->url('/events'), ['kind' => 'point', 'site' => $this->dp])->json('event.public_id');

        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/legs/{$leg}")->assertNotFound();
        $this->asCompany(self::OTHER_COMPANY)->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => [['sku' => 'NPK-50', 'quantity' => 1]]])->assertNotFound();
        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/events/{$event}/report")->assertNotFound();
        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/sites/{$this->central}")->assertNotFound();
    }
}

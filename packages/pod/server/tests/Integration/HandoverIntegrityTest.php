<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\Programme;
use Fleetbase\Pod\Models\ProgrammeItem;
use Fleetbase\Pod\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * A handover is checked against the entitlement as it is when it's written,
 * and completes when all lots of an item add up to the entitlement.
 */
final class HandoverIntegrityTest extends IntegrationTestCase
{
    private string $event;

    private Beneficiary $farmer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);

        $programme = $this->asCompany()->postJson('int/v1/pod/programmes', ['name' => 'Rice Support', 'operator_type' => 'ngo'])->json('programme.public_id');
        $this->asCompany()->putJson("int/v1/pod/programmes/{$programme}/items", ['items' => [['sku' => 'RICE-25', 'name' => 'Rice seed, 25 kg', 'category' => 'seed', 'unit' => 'bag']]])->assertOk();
        $this->asCompany()->putJson("int/v1/pod/programmes/{$programme}/entitlement-rules", ['rules' => [['sku' => 'RICE-25', 'quantity' => 2]]])->assertOk();
        $dp = $this->asCompany()->postJson('int/v1/pod/sites', ['name' => 'Baiwalla DP', 'type' => 'distribution_point'])->json('site.public_id');
        $this->asCompany()->postJson("int/v1/pod/sites/{$dp}/stock/receive", ['programme' => $programme, 'reference' => 'GRN', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'RICE-25', 'lot' => 'R8', 'quantity' => 5]]])->assertCreated();

        $model        = Programme::where('public_id', $programme)->first();
        $this->farmer = Beneficiary::create(['company_uuid' => self::COMPANY, 'programme_uuid' => $model->uuid, 'name' => 'Aminata Kamara', 'sex' => 'female', 'village' => 'Baiwalla', 'admin_3' => 'SL010101']);
        $this->farmer->card_code = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$this->farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->json('card_code');
        $this->asCompany()->postJson("int/v1/pod/programmes/{$programme}/entitlements/generate")->assertOk();

        $this->event = $this->asCompany()->postJson("int/v1/pod/programmes/{$programme}/events", ['kind' => 'point', 'site' => $dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/assign", ['admin_3' => 'SL010101'])->assertOk();
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'RICE-25', 'lot' => 'R8', 'quantity' => 5]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();
    }

    private function handover(array $lines)
    {
        return $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/handovers", [
            'card_code' => $this->farmer->card_code,
            'identity'  => ['face_matched' => true],
            'lines'     => $lines,
            'evidence'  => [['type' => 'photo', 'file' => 'file_1'], ['type' => 'mark']],
        ]);
    }

    public function testTwoLotsOfOneItemThatMakeUpTheEntitlementAreAFullDelivery(): void
    {
        // 2 bags: the last bag of lot R7 and one from lot R8.
        $response = $this->handover([['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'RICE-25', 'lot' => 'R8', 'quantity' => 1]]);

        $response->assertCreated();
        $this->assertSame('delivered', $response->json('handover.outcome'));
        $this->assertSame([2.0, Entitlement::COLLECTED], [(float) Entitlement::first()->collected_quantity, Entitlement::first()->status]);
    }

    public function testAHandoverRecordedMeanwhileByAnotherPhoneIsNotHandedOverAgain(): void
    {
        // Another phone's handover commits after this request has read the entitlement
        // (stood in for by updating the row just before the write starts).
        $done = false;
        ProgrammeItem::retrieved(function () use (&$done) {
            if (!$done) {
                $done = true;
                DB::table('pod_entitlements')->update(['collected_quantity' => 2, 'status' => Entitlement::COLLECTED]);
            }
        });

        $this->handover([['sku' => 'RICE-25', 'lot' => 'R8', 'quantity' => 2]])
            ->assertStatus(422)->assertJson(['error' => 'already_collected']);

        $this->assertTrue($done);
        $this->assertSame(0, Handover::count(), 'nothing recorded');
        $this->assertSame(0, StockMovement::where('reason', 'handed_over')->count(), 'no stock taken twice');
        $this->assertSame(2.0, (float) Entitlement::first()->collected_quantity, 'the other phone\'s collection is kept, not overwritten');
    }
}

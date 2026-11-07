<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\CardEvent;
use Fleetbase\Pod\Models\Entitlement;
use Fleetbase\Pod\Models\Programme;

final class EntitlementAndCardFlowTest extends IntegrationTestCase
{
    private Programme $programme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);

        $id = $this->asCompany()->postJson('int/v1/pod/programmes', [
            'name'          => 'Rice Seed and Fertiliser Support',
            'operator_type' => 'ngo',
            'season'        => '2027 Main Season',
            'rules'         => ['card_issuer' => 'Example NGO'],
        ])->json('programme.public_id');

        $this->programme = Programme::where('public_id', $id)->firstOrFail();
    }

    private function farmer(string $name, array $attributes = []): Beneficiary
    {
        return Beneficiary::create($attributes + [
            'company_uuid'   => self::COMPANY,
            'programme_uuid' => $this->programme->uuid,
            'name'           => $name,
            'sex'            => 'female',
            'birth_year'     => 1985,
            'admin_1'        => 'SL01',
            'admin_2'        => 'SL0101',
            'admin_3'        => 'SL010101',
            'village'        => 'Baiwalla',
            'farm_size'      => '2 acres',
            'crop'           => 'Rice',
        ]);
    }

    private function url(string $path): string
    {
        return 'int/v1/pod/programmes/' . $this->programme->public_id . $path;
    }

    private function setUpPackage(): void
    {
        $this->asCompany()->putJson($this->url('/items'), ['items' => [
            ['sku' => 'RICE-25', 'name' => 'Rice seed NERICA L19, 25 kg', 'category' => 'seed', 'unit' => 'bag'],
            ['sku' => 'NPK-50', 'name' => 'NPK 15-15-15, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag', 'max_per_beneficiary' => 4],
            ['sku' => 'HERB-1L', 'name' => 'Herbicide, 1 litre', 'category' => 'pesticide', 'unit' => 'bottle', 'max_per_beneficiary' => 2],
        ]])->assertOk();

        $this->asCompany()->putJson($this->url('/entitlement-rules'), ['rules' => [
            ['sku' => 'RICE-25', 'quantity' => 1],
            ['sku' => 'NPK-50', 'quantity' => 1, 'per' => 'farm_size_acres'],
            ['sku' => 'HERB-1L', 'quantity' => 1],
        ]])->assertOk();
    }

    private function verify(Beneficiary $farmer, array $updates = [])
    {
        return $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true, 'updates' => $updates]);
    }

    public function testEntitlementsAreGeneratedForVerifiedFarmersOnly(): void
    {
        $this->setUpPackage();
        $aminata = $this->farmer('Aminata Kamara');
        $isatu   = $this->farmer('Isatu Koroma', ['farm_size' => '5 acres']);
        $this->farmer('Not Yet Verified');

        $this->verify($aminata)->assertOk();
        $this->verify($isatu, ['trained_restricted_products' => '2026-03-01'])->assertOk();

        // Dry run first: counts, nothing saved.
        $dry = $this->asCompany()->postJson($this->url('/entitlements/generate'), ['dry_run' => true]);
        $dry->assertOk();
        $this->assertSame(2, $dry->json('eligible'));
        $this->assertSame(1, $dry->json('not_eligible'));
        $this->assertSame(5, $dry->json('created'), 'Aminata: rice + NPK; Isatu: rice + NPK + herbicide');
        $this->assertSame(['HERB-1L:not_trained' => 1], $dry->json('skipped'));
        $this->assertSame(0, Entitlement::count());

        $run = $this->asCompany()->postJson($this->url('/entitlements/generate'));
        $run->assertOk();
        $this->assertSame(5, $run->json('created'));
        $this->assertEquals(['HERB-1L' => 1, 'NPK-50' => 6, 'RICE-25' => 2], $run->json('totals'), 'NPK: 2 + 4 (5 acres capped at 4)');

        // Running again changes nothing.
        $again = $this->asCompany()->postJson($this->url('/entitlements/generate'))->json();
        $this->assertSame([0, 0, 0, 5], [$again['created'], $again['updated'], $again['cancelled'], $again['unchanged']]);
    }

    public function testChangedRulesUpdateUntouchedEntitlementsButNeverCollectedOnes(): void
    {
        $this->setUpPackage();
        $aminata = $this->farmer('Aminata Kamara');
        $isatu   = $this->farmer('Isatu Koroma');
        $this->verify($aminata)->assertOk();
        $this->verify($isatu)->assertOk();
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();

        // Isatu has already collected her rice.
        Entitlement::where('beneficiary_uuid', $isatu->uuid)->where('sku', 'RICE-25')->update(['collected_quantity' => 1, 'status' => Entitlement::COLLECTED]);

        // The programme raises rice to 2 bags and drops NPK.
        $this->asCompany()->putJson($this->url('/entitlement-rules'), ['rules' => [['sku' => 'RICE-25', 'quantity' => 2]]])->assertOk();
        $result = $this->asCompany()->postJson($this->url('/entitlements/generate'))->json();

        $this->assertSame(1, $result['updated'], "Aminata's rice: 1 -> 2");
        $this->assertSame(1, $result['locked'], "Isatu's collected rice isn't changed");
        $this->assertSame(2, $result['cancelled'], 'both NPK entitlements cancelled');

        $this->assertSame(2.0, Entitlement::where('beneficiary_uuid', $aminata->uuid)->where('sku', 'RICE-25')->value('quantity') + 0.0);
        $this->assertSame(1.0, Entitlement::where('beneficiary_uuid', $isatu->uuid)->where('sku', 'RICE-25')->value('quantity') + 0.0);
        $this->assertSame('rule_removed', Entitlement::where('sku', 'NPK-50')->value('cancel_reason'));

        // NPK's entitlements are all cancelled, so NPK can be removed. Rice still has live
        // entitlements, so it can't.
        $this->asCompany()->putJson($this->url('/items'), ['items' => [
            ['sku' => 'RICE-25', 'name' => 'Rice seed', 'category' => 'seed', 'unit' => 'bag'],
            ['sku' => 'HERB-1L', 'name' => 'Herbicide', 'category' => 'pesticide', 'unit' => 'bottle'],
        ]])->assertOk();
        $this->asCompany()->putJson($this->url('/items'), ['items' => [
            ['sku' => 'HERB-1L', 'name' => 'Herbicide', 'category' => 'pesticide', 'unit' => 'bottle'],
        ]])->assertStatus(422)->assertJsonFragment(['errors' => ['These items still have entitlements: RICE-25. Remove their rules and regenerate entitlements first.']]);
    }

    public function testRulesAreCheckedAgainstTheItemList(): void
    {
        $this->setUpPackage();

        $this->asCompany()->putJson($this->url('/entitlement-rules'), ['rules' => [['sku' => 'MAIZE-25', 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonFragment(['errors' => ['Rule 1: item "MAIZE-25" is not in the programme\'s item list.']]);
    }

    public function testProgrammesThatVerifyAtFirstHandoverGetEntitlementsBeforeVerification(): void
    {
        $this->programme->update(['rules' => ['verify_on_first_handover' => true]]);
        $this->setUpPackage();
        $this->farmer('Unverified But Allowed');

        $this->assertSame(2, $this->asCompany()->postJson($this->url('/entitlements/generate'))->json('created'));
    }

    public function testTheSummaryGivesQuantitiesByDistrictAndChiefdom(): void
    {
        $this->artisan('pod:seed-admin-areas')->assertSuccessful();
        $this->setUpPackage();
        foreach ([$this->farmer('A One'), $this->farmer('B Two'), $this->farmer('C Three', ['admin_2' => 'SL0301', 'admin_3' => 'SL030101', 'admin_1' => 'SL03'])] as $f) {
            $this->verify($f)->assertOk();
        }
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();

        $rows = $this->asCompany()->getJson($this->url('/entitlements/summary?level=2'))->json('rows');

        $this->assertSame([
            ['pcode' => 'SL0101', 'area' => 'Kailahun', 'sku' => 'NPK-50', 'unit' => 'bag', 'beneficiaries' => 2, 'quantity' => 4, 'collected' => 0],
            ['pcode' => 'SL0101', 'area' => 'Kailahun', 'sku' => 'RICE-25', 'unit' => 'bag', 'beneficiaries' => 2, 'quantity' => 2, 'collected' => 0],
            ['pcode' => 'SL0301', 'area' => 'Bo', 'sku' => 'NPK-50', 'unit' => 'bag', 'beneficiaries' => 1, 'quantity' => 2, 'collected' => 0],
            ['pcode' => 'SL0301', 'area' => 'Bo', 'sku' => 'RICE-25', 'unit' => 'bag', 'beneficiaries' => 1, 'quantity' => 1, 'collected' => 0],
        ], $rows);
    }

    public function testVerificationIssuesACardThatChecksOut(): void
    {
        $farmer = $this->farmer('Aminata Kamara');

        $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => false])
            ->assertStatus(422);

        $verified = $this->verify($farmer, ['household_size' => 6]);
        $verified->assertOk();
        $this->assertSame('verified', $verified->json('beneficiary.verification_status'));
        $this->assertSame(1, $verified->json('beneficiary.card_issue'));
        $this->assertSame(6, $verified->json('beneficiary.household_size'));
        $code = $verified->json('card_code');
        $this->assertStringStartsWith('FBP1.' . $this->programme->public_id . '.' . $farmer->public_id . '.1.', $code);

        $check = $this->asCompany()->postJson($this->url('/cards/check'), ['code' => $code]);
        $check->assertOk()->assertJson(['valid' => true, 'beneficiary' => ['name' => 'Aminata Kamara']]);

        $this->verify($farmer)->assertStatus(422)->assertJsonFragment(['errors' => ['This beneficiary is already verified.']]);
        $this->assertSame(['issued'], CardEvent::pluck('action')->all());
    }

    public function testAReplacementCardRevokesTheOldOne(): void
    {
        $farmer  = $this->farmer('Aminata Kamara');
        $oldCode = $this->verify($farmer)->json('card_code');

        $new = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/card/reissue", ['reason' => 'lost']);
        $new->assertOk();
        $this->assertSame(2, $new->json('beneficiary.card_issue'));

        $this->assertSame(['valid' => false, 'reason' => 'revoked'], array_intersect_key($this->asCompany()->postJson($this->url('/cards/check'), ['code' => $oldCode])->json(), ['valid' => 1, 'reason' => 1]));
        $this->assertTrue($this->asCompany()->postJson($this->url('/cards/check'), ['code' => $new->json('card_code')])->json('valid'));

        $this->assertSame([['issued', 1, 'community_validation'], ['reissued', 2, 'lost']], CardEvent::orderBy('id')->get()->map(fn ($e) => [$e->action, $e->issue_number, $e->reason])->all());
    }

    public function testRejectionRevokesTheCardAndCancelsEntitlements(): void
    {
        $this->setUpPackage();
        $farmer = $this->farmer('Moved Away');
        $code   = $this->verify($farmer)->json('card_code');
        $this->asCompany()->postJson($this->url('/entitlements/generate'))->assertOk();

        $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/reject", ['reason' => 'moved'])
            ->assertOk()
            ->assertJson(['beneficiary' => ['verification_status' => 'rejected', 'status' => 'moved']]);

        $this->assertSame('not_verified', $this->asCompany()->postJson($this->url('/cards/check'), ['code' => $code])->json('reason'));
        $this->assertSame(['cancelled'], Entitlement::where('beneficiary_uuid', $farmer->uuid)->distinct()->pluck('status')->all());

        // Found again later: verified again with a NEW card number; the revoked card stays revoked.
        $farmer->update(['status' => 'active']);
        $again = $this->verify($farmer);
        $again->assertOk();
        $this->assertSame(2, $again->json('beneficiary.card_issue'));
        $this->assertSame('revoked', $this->asCompany()->postJson($this->url('/cards/check'), ['code' => $code])->json('reason'));
        $this->assertSame(['issued', 'revoked', 'reissued'], CardEvent::orderBy('id')->pluck('action')->all());
    }

    public function testForgedAndForeignCodesAreRefused(): void
    {
        $farmer = $this->farmer('Aminata Kamara');
        $code   = $this->verify($farmer)->json('card_code');

        $forged = substr($code, 0, -1) . (str_ends_with($code, 'A') ? 'B' : 'A');
        $this->assertSame('bad_signature', $this->asCompany()->postJson($this->url('/cards/check'), ['code' => $forged])->json('reason'));
        $this->assertSame('malformed', $this->asCompany()->postJson($this->url('/cards/check'), ['code' => 'hello'])->json('reason'));

        // Another company can't check this programme's cards at all.
        $this->asCompany(self::OTHER_COMPANY)->postJson($this->url('/cards/check'), ['code' => $code])->assertNotFound();
    }

    public function testPrintsCardsAsAPdfAndLogsThePrint(): void
    {
        $this->artisan('pod:seed-admin-areas')->assertSuccessful();
        $a = $this->farmer('Aminata Kamara');
        $b = $this->farmer('Mohamed Bangura', ['sex' => 'male']);
        $c = $this->farmer('Not Verified');
        $this->verify($a)->assertOk();
        $this->verify($b)->assertOk();

        $this->asCompany()->postJson($this->url('/cards/pdf'), [])->assertStatus(422);

        $response = $this->asCompany()->post($this->url('/cards/pdf'), ['admin_3' => 'SL010101']);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame('2', $response->headers->get('x-cards-printed'));
        $this->assertSame('1', $response->headers->get('x-cards-skipped'), 'the unverified farmer is skipped');

        $batch = $response->headers->get('x-print-batch');
        $this->assertSame(2, CardEvent::where('action', 'printed')->where('print_batch', $batch)->count());

        // Only unverified farmers selected: nothing to print.
        $this->asCompany()->postJson($this->url('/cards/pdf'), ['beneficiaries' => [$c->public_id]])
            ->assertStatus(422)
            ->assertJsonFragment(['errors' => ['None of the selected beneficiaries has a card to print. Verify them first.']]);
    }

    public function testCardEventsCannotBeChangedOrDeleted(): void
    {
        $farmer = $this->farmer('Aminata Kamara');
        $this->verify($farmer)->assertOk();
        $event = CardEvent::firstOrFail();

        try {
            $event->update(['action' => 'printed']);
            $this->fail('A card event was changed.');
        } catch (\LogicException) {
        }
        try {
            $event->delete();
            $this->fail('A card event was deleted.');
        } catch (\LogicException) {
        }

        $this->assertSame('issued', CardEvent::firstOrFail()->action);
    }

    public function testListsAndShowsBeneficiariesForTheCompanyOnly(): void
    {
        $farmer = $this->farmer('Aminata Kamara');
        $this->farmer('Mohamed Bangura');
        $this->verify($farmer)->assertOk();

        $list = $this->asCompany()->getJson($this->url('/beneficiaries?verification_status=verified'));
        $list->assertOk();
        $this->assertSame(['Aminata Kamara'], array_column($list->json('data'), 'name'));
        $this->assertSame(2, $this->asCompany()->getJson($this->url('/beneficiaries?q=a'))->json('total'));

        $show = $this->asCompany()->getJson("int/v1/pod/beneficiaries/{$farmer->public_id}");
        $show->assertOk();
        $this->assertSame('issued', $show->json('beneficiary.card_events.0.action'));
        $this->assertArrayNotHasKey('id_hash', $show->json('beneficiary'));

        $this->asCompany(self::OTHER_COMPANY)->getJson("int/v1/pod/beneficiaries/{$farmer->public_id}")->assertNotFound();
        $this->asCompany(self::OTHER_COMPANY)->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/card/reissue", ['reason' => 'lost'])->assertNotFound();
    }
}

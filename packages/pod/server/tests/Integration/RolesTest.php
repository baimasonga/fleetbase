<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Programme;
use Illuminate\Support\Facades\Route;

/**
 * Who may do what through the API, by Invendis role (Auth/Schemas/Pod.php).
 */
final class RolesTest extends IntegrationTestCase
{
    private const SYNC_ONLY = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    private string $programme;

    private string $central;

    private string $dp;

    private string $event;

    private Beneficiary $farmer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);
        $this->app->scoped(\Fleetbase\Pod\Auth\Gate::class, fn () => new \Fleetbase\Pod\Auth\RoleGate(self::ROLES + [self::SYNC_ONLY => ['pod sync field']]));

        $this->programme = $this->asCompany()->postJson('int/v1/pod/programmes', ['name' => 'Rice Support', 'operator_type' => 'ngo'])->json('programme.public_id');
        $this->asCompany()->putJson("int/v1/pod/programmes/{$this->programme}/items", ['items' => [['sku' => 'RICE-25', 'name' => 'Rice seed, 25 kg', 'category' => 'seed', 'unit' => 'bag']]])->assertOk();
        $this->asCompany()->putJson("int/v1/pod/programmes/{$this->programme}/entitlement-rules", ['rules' => [['sku' => 'RICE-25', 'quantity' => 1]]])->assertOk();
        $this->central = $this->asCompany()->postJson('int/v1/pod/sites', ['name' => 'Kenema Central', 'type' => 'central_warehouse'])->json('site.public_id');
        $this->dp      = $this->asCompany()->postJson('int/v1/pod/sites', ['name' => 'Baiwalla DP', 'type' => 'distribution_point'])->json('site.public_id');
        $this->asCompany()->postJson("int/v1/pod/sites/{$this->central}/stock/receive", ['programme' => $this->programme, 'reference' => 'GRN', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 50]]])->assertCreated();
        $this->asCompany()->postJson("int/v1/pod/sites/{$this->dp}/stock/receive", ['programme' => $this->programme, 'reference' => 'GRN', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10]]])->assertCreated();

        $model        = Programme::where('public_id', $this->programme)->first();
        $this->farmer = Beneficiary::create(['company_uuid' => self::COMPANY, 'programme_uuid' => $model->uuid, 'name' => 'Aminata Kamara', 'sex' => 'female', 'village' => 'Baiwalla', 'admin_3' => 'SL010101']);
        $this->farmer->card_code = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$this->farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->json('card_code');
        $this->asCompany()->postJson("int/v1/pod/programmes/{$this->programme}/entitlements/generate")->assertOk();
        $this->event = $this->asCompany()->postJson("int/v1/pod/programmes/{$this->programme}/events", ['kind' => 'point', 'site' => $this->dp, 'name' => 'Baiwalla day 1'])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/assign", ['admin_3' => 'SL010101'])->assertOk();
    }

    private function as(string $user): static
    {
        return $this->asCompany(self::COMPANY, $user);
    }

    private function trip(?string $transporter): string
    {
        $leg = $this->asCompany()->postJson("int/v1/pod/programmes/{$this->programme}/legs", ['origin' => $this->central, 'destination' => $this->dp, 'transporter_kind' => 'hired', 'transporter_name' => 'Musa Transport', 'transporter_user_uuid' => $transporter, 'vehicle_plate' => 'AKV 123', 'waybill_no' => 'WB-' . uniqid()])->assertCreated()->json('leg.public_id');
        $r = $this->as(self::RECEIVER)->postJson("int/v1/pod/legs/{$leg}/load", ['lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 5]], 'seals' => ['S-1'], 'restricted_segregated' => true]);
        $this->assertSame(200, $r->status(), $r->getContent());

        return $leg;
    }

    private function handover(string $id): array
    {
        return ['kind' => 'handover', 'event' => $this->event, 'client_handover_id' => $id, 'captured_at' => now()->subMinutes(5)->toIso8601String(),
            'card_code' => $this->farmer->card_code, 'identity' => ['face_matched' => true],
            'lines'     => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]],
            'evidence'  => [['type' => 'photo', 'media' => "{$id}-p", 'sha256' => str_repeat('a', 64)], ['type' => 'mark']]];
    }

    public function testEveryRouteNeedsAPermissionExceptReferenceLists(): void
    {
        $open = [];
        foreach (Route::getRoutes() as $route) {
            if (!str_contains($route->uri(), 'v1/pod/')) {
                continue;
            }
            if (!array_filter($route->gatherMiddleware(), fn ($m) => str_starts_with($m, 'pod.can:'))) {
                $open[] = $route->uri();
            }
        }
        sort($open);
        $this->assertSame(['int/v1/pod/admin-areas', 'int/v1/pod/countries', 'int/v1/pod/me'], $open);
    }

    public function testSomeoneWithoutARoleIsRefusedWithAReason(): void
    {
        $this->as(self::NO_ROLE)->getJson('int/v1/pod/programmes')
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden', 'permission' => 'pod list programme'])
            ->assertJsonFragment(['errors' => ['Your account is not allowed to do this (pod list programme). Ask an administrator to give you the right Invendis role.']]);
        $this->as(self::NO_ROLE)->getJson('int/v1/pod/countries')->assertOk();
        $this->assertSame(array_fill_keys(['field', 'open_day', 'close_day', 'hand_over', 'record_stock', 'review_conflicts', 'resolve_conflicts'], false), $this->as(self::NO_ROLE)->getJson('int/v1/pod/me')->json('can'));
    }

    public function testAFieldOfficerWorksTheDayButCannotReviewOrSetUp(): void
    {
        $this->as(self::OFFICER)->getJson("int/v1/pod/events/{$this->event}/bundle")->assertOk();
        $this->as(self::OFFICER)->postJson("int/v1/pod/events/{$this->event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();
        $this->assertSame('accepted', $this->as(self::OFFICER)->postJson('int/v1/pod/field/sync', ['items' => [$this->handover('o-0001')]])->json('results.0.status'));

        $this->as(self::OFFICER)->getJson('int/v1/pod/conflicts')->assertStatus(403);
        $this->as(self::OFFICER)->postJson('int/v1/pod/programmes', ['name' => 'X', 'operator_type' => 'ngo'])->assertStatus(403);
        $this->as(self::OFFICER)->postJson("int/v1/pod/programmes/{$this->programme}/entitlements/generate")->assertStatus(403);
        $this->as(self::OFFICER)->postJson("int/v1/pod/beneficiaries/{$this->farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->assertStatus(403);
        $this->assertSame(['field' => true, 'open_day' => true, 'close_day' => true, 'hand_over' => true, 'record_stock' => true, 'review_conflicts' => false, 'resolve_conflicts' => false], $this->as(self::OFFICER)->getJson('int/v1/pod/me')->json('can'));
        $this->assertTrue($this->as(self::SUPERVISOR_USER)->getJson('int/v1/pod/me')->json('can.resolve_conflicts'));
    }

    public function testFieldRecordsThePersonMayNotSendAreKeptOffTheServerNotLost(): void
    {
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10]], 'witnesses' => [['name' => 'Chief', 'role' => 'chief']]])->assertOk();

        $results = $this->as(self::SYNC_ONLY)->postJson('int/v1/pod/field/sync', ['items' => [$this->handover('s-0001')]])->assertOk()->json('results');
        $this->assertSame([['client_handover_id' => 's-0001', 'status' => 'forbidden', 'error' => 'forbidden']], array_map(fn ($r) => array_intersect_key($r, array_flip(['client_handover_id', 'status', 'error'])), $results));
        $this->assertSame(0, \Fleetbase\Pod\Models\Handover::count());
        $this->assertSame(0, \Fleetbase\Pod\Models\SyncConflict::count(), 'not a conflict: it can be sent again once the role is given');

        $this->as(self::MONITOR)->postJson('int/v1/pod/field/sync', ['items' => [$this->handover('s-0001')]])->assertStatus(403);
    }

    public function testATransporterSeesAndMovesOnlyTheirOwnTrips(): void
    {
        $mine   = $this->trip(self::TRANSPORTER);
        $theirs = $this->trip(null);

        $this->assertSame([$mine], array_column($this->as(self::TRANSPORTER)->getJson('int/v1/pod/legs')->json('data'), 'public_id'));
        $this->assertSame([$mine], array_column($this->as(self::TRANSPORTER)->getJson("int/v1/pod/programmes/{$this->programme}/legs")->json('data'), 'public_id'));
        $this->assertCount(2, $this->as(self::RECEIVER)->getJson('int/v1/pod/legs')->json('data'));

        $this->as(self::TRANSPORTER)->getJson("int/v1/pod/legs/{$mine}")->assertOk();
        $this->as(self::TRANSPORTER)->getJson("int/v1/pod/legs/{$theirs}")->assertNotFound();
        $this->as(self::TRANSPORTER)->postJson("int/v1/pod/legs/{$theirs}/depart")->assertNotFound();
        $this->as(self::TRANSPORTER)->postJson("int/v1/pod/legs/{$mine}/depart")->assertOk()->assertJson(['leg' => ['status' => 'in_transit']]);
        $this->as(self::TRANSPORTER)->postJson("int/v1/pod/legs/{$mine}/arrive")->assertOk();

        // Receiving and closing are for the storekeeper at the destination.
        $this->as(self::TRANSPORTER)->postJson("int/v1/pod/legs/{$mine}/receive", ['lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'good' => 5]]])->assertStatus(403);
        $this->as(self::TRANSPORTER)->getJson("int/v1/pod/programmes/{$this->programme}/beneficiaries")->assertStatus(403);
    }

    public function testMonitorsCanReadButNotChange(): void
    {
        $this->as(self::MONITOR)->getJson('int/v1/pod/programmes')->assertOk();
        $this->as(self::MONITOR)->getJson("int/v1/pod/programmes/{$this->programme}/beneficiaries")->assertOk();
        $this->as(self::MONITOR)->getJson("int/v1/pod/events/{$this->event}")->assertOk();
        $this->as(self::MONITOR)->getJson('int/v1/pod/conflicts')->assertOk();
        $this->as(self::MONITOR)->getJson('int/v1/pod/legs')->assertOk();

        $this->as(self::MONITOR)->putJson("int/v1/pod/programmes/{$this->programme}", ['name' => 'Renamed'])->assertStatus(403);
        $this->as(self::MONITOR)->postJson("int/v1/pod/events/{$this->event}/open", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10]]])->assertStatus(403);
        $this->as(self::MONITOR)->postJson("int/v1/pod/sites/{$this->dp}/stock/receive", ['programme' => $this->programme, 'reference' => 'X', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1]]])->assertStatus(403);
        $this->as(self::MONITOR)->postJson('int/v1/pod/conflicts/x/resolve', ['resolution' => 'reviewed', 'notes' => 'Looks fine to me.'])->assertStatus(403);
    }
}

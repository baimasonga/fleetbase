<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\Beneficiary;
use Fleetbase\Pod\Models\Discrepancy;
use Fleetbase\Pod\Models\DistributionEvent;
use Fleetbase\Pod\Models\Handover;
use Fleetbase\Pod\Models\SyncConflict;

/**
 * Opening and closing a distribution day from phones, offline.
 */
final class FieldOpenCloseTest extends IntegrationTestCase
{
    private string $event;

    private string $dp;

    private array $farmers = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['pod.cards.secret' => 'test-card-secret']);

        $programme = $this->asCompany()->postJson('int/v1/pod/programmes', ['name' => 'Rice Support', 'operator_type' => 'ngo'])->json('programme.public_id');
        $this->asCompany()->putJson("int/v1/pod/programmes/{$programme}/items", ['items' => [
            ['sku' => 'RICE-25', 'name' => 'Rice seed', 'category' => 'seed', 'unit' => 'bag'],
            ['sku' => 'NPK-50', 'name' => 'NPK', 'category' => 'fertiliser', 'unit' => 'bag'],
        ]])->assertOk();
        $this->asCompany()->putJson("int/v1/pod/programmes/{$programme}/entitlement-rules", ['rules' => [['sku' => 'RICE-25', 'quantity' => 1], ['sku' => 'NPK-50', 'quantity' => 2]]])->assertOk();

        $this->dp = $this->asCompany()->postJson('int/v1/pod/sites', ['name' => 'Baiwalla DP', 'type' => 'distribution_point'])->json('site.public_id');
        $this->asCompany()->postJson("int/v1/pod/sites/{$this->dp}/stock/receive", ['programme' => $programme, 'reference' => 'GRN', 'lines' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]]])->assertCreated();

        $programmeModel = \Fleetbase\Pod\Models\Programme::where('public_id', $programme)->first();
        foreach (['Aminata Kamara', 'Isatu Koroma'] as $name) {
            $farmer = Beneficiary::create(['company_uuid' => self::COMPANY, 'programme_uuid' => $programmeModel->uuid, 'name' => $name, 'sex' => 'female', 'admin_3' => 'SL010101']);
            $farmer->card_code = $this->asCompany()->postJson("int/v1/pod/beneficiaries/{$farmer->public_id}/verify", ['method' => 'community_validation', 'consent' => true])->json('card_code');
            $this->farmers[$name] = $farmer;
        }
        $this->asCompany()->postJson("int/v1/pod/programmes/{$programme}/entitlements/generate")->assertOk();
        $this->event = $this->asCompany()->postJson("int/v1/pod/programmes/{$programme}/events", ['kind' => 'point', 'site' => $this->dp, 'name' => 'Day 1'])->json('event.public_id');
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/assign", ['admin_3' => 'SL010101'])->assertOk();
    }

    private function sync(array ...$items): array
    {
        $response = $this->asCompany()->postJson('int/v1/pod/field/sync', ['items' => $items]);
        $response->assertOk();

        return collect($response->json('results'))->keyBy('client_handover_id')->all();
    }

    private function open(string $id, string $at, array $stock = null): array
    {
        return ['kind' => 'open', 'event' => $this->event, 'client_handover_id' => $id, 'captured_at' => $at,
            'stock'     => $stock ?? [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]],
            'witnesses' => [['name' => 'Chief Musa Kallon', 'role' => 'chief', 'signature' => 'p1-m000001', 'sha256' => str_repeat('a', 64)]]];
    }

    private function handover(string $id, string $farmer, string $at, int $npk = 2): array
    {
        return ['kind' => 'handover', 'event' => $this->event, 'client_handover_id' => $id, 'captured_at' => $at,
            'card_code' => $this->farmers[$farmer]->card_code, 'identity' => ['face_matched' => true],
            'lines'     => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 1], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => $npk]],
            'evidence'  => [['type' => 'photo'], ['type' => 'mark']]];
    }

    private function close(string $id, string $at, int $rice, int $npk): array
    {
        return ['kind' => 'close', 'event' => $this->event, 'client_handover_id' => $id, 'captured_at' => $at,
            'stock'     => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => $rice], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => $npk]],
            'witnesses' => [['name' => 'Chief Musa Kallon', 'role' => 'chief']]];
    }

    public function testADayOpenedServedAndClosedOfflineSyncsInOneBatch(): void
    {
        $t = fn (int $min) => now()->subMinutes(300 - $min)->toIso8601String();

        $results = $this->sync(
            $this->close('p1-close', $t(200), 8, 16),          // sent first, processed last
            $this->handover('p1-0002', 'Isatu Koroma', $t(60)),
            $this->open('p1-open', $t(0)),
            $this->handover('p1-0001', 'Aminata Kamara', $t(30)),
        );

        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted'], [$results['p1-open']['status'], $results['p1-0001']['status'], $results['p1-0002']['status'], $results['p1-close']['status']]);

        $event = DistributionEvent::where('public_id', $this->event)->first();
        $this->assertSame('closed', $event->status);
        $this->assertTrue($event->reconciliation['balanced']);
        $this->assertSame($t(0), $event->opened_at->toIso8601String(), 'dated when it happened, not when it synced');
        $this->assertSame('p1-m000001', $event->witnesses['open'][0]['signature']);

        // The same batch again: nothing happens twice.
        $again = $this->sync($this->open('p1-open', $t(0)), $this->handover('p1-0001', 'Aminata Kamara', $t(30)), $this->close('p1-close', $t(200), 8, 16));
        $this->assertSame(['replayed', 'replayed', 'replayed'], array_column($again, 'status'));
        $this->assertSame(2, Handover::count());
    }

    public function testHandoversFromAPhoneThatJoinedWaitForTheOpening(): void
    {
        $t = fn (int $min) => now()->subMinutes(300 - $min)->toIso8601String();

        // Phone 2 joined the day opened on phone 1, and syncs first.
        $early = $this->sync($this->handover('p2-0001', 'Isatu Koroma', $t(40)));
        $this->assertSame(['deferred', 'not_open_yet'], [$early['p2-0001']['status'], $early['p2-0001']['error']]);
        $this->assertSame(0, SyncConflict::count(), 'not a conflict: it will be sent again');

        $this->sync($this->open('p1-open', $t(0)));
        $this->assertSame('accepted', $this->sync($this->handover('p2-0001', 'Isatu Koroma', $t(40)))['p2-0001']['status']);
    }

    public function testASecondOpeningCountIsKeptForReview(): void
    {
        $this->sync($this->open('p1-open', now()->subHour()->toIso8601String()));
        $second = $this->sync($this->open('p2-open', now()->subMinutes(50)->toIso8601String()));

        $this->assertSame(['conflict', 'already_opened'], [$second['p2-open']['status'], $second['p2-open']['error']]);
        $this->assertSame('open', SyncConflict::first()->kind);
    }

    public function testAClosingThatDoesNotBalanceWaitsForADiscrepancy(): void
    {
        $t = fn (int $min) => now()->subMinutes(300 - $min)->toIso8601String();
        $this->sync($this->open('p1-open', $t(0)), $this->handover('p1-0001', 'Aminata Kamara', $t(30)));

        // The count finds 17 NPK where the records say 18.
        $first = $this->sync($this->close('p1-close', $t(200), 9, 17));
        $this->assertSame('unbalanced', $first['p1-close']['status']);
        $this->assertEquals([['sku' => 'NPK-50', 'lot' => 'L1', 'variance' => 1]], array_map(fn ($l) => array_intersect_key($l, array_flip(['sku', 'lot', 'variance'])), $first['p1-close']['lines']));
        $this->assertSame(0, SyncConflict::count(), 'not stored: the phone sends it again');
        $this->assertSame('open', DistributionEvent::where('public_id', $this->event)->value('status'));

        // The officer records the torn bag (after the close, on the phone); both go in the next batch.
        $discrepancy = ['kind' => 'discrepancy', 'event' => $this->event, 'client_handover_id' => 'p1-d0001', 'captured_at' => $t(210), 'sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 1, 'reason' => 'torn_bag', 'notes' => 'Bag torn while unloading.'];
        $second = $this->sync($this->close('p1-close', $t(200), 9, 17), $discrepancy);

        $this->assertSame('accepted', $second['p1-d0001']['status']);
        $this->assertSame('accepted', $second['p1-close']['status'], 'the close is processed after the discrepancy');
        $this->assertSame('closed', DistributionEvent::where('public_id', $this->event)->value('status'));

        // The discrepancy isn't recorded twice.
        $this->assertSame('replayed', $this->sync($discrepancy)['p1-d0001']['status']);
        $this->assertSame(1, Discrepancy::count());
    }

    public function testAPhoneClosingAfterTheConsoleClosedIsKeptForReview(): void
    {
        $t = fn (int $min) => now()->subMinutes(300 - $min)->toIso8601String();
        $this->sync($this->open('p1-open', $t(0)));
        $this->asCompany()->postJson("int/v1/pod/events/{$this->event}/close", ['stock' => [['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]], 'witnesses' => [['name' => 'X', 'role' => 'chief']]])->assertOk();

        // Closed from the console already: a phone's later closing is kept for review.
        $late = $this->sync($this->close('p1-close', $t(200), 10, 20));
        $this->assertSame(['conflict', 'already_closed'], [$late['p1-close']['status'], $late['p1-close']['error']]);
    }

    public function testTheBundleForAPlannedDayOffersTheSiteStockToCount(): void
    {
        $bundle = $this->asCompany()->getJson("int/v1/pod/events/{$this->event}/bundle");

        $this->assertSame('planned', $bundle->json('event.status'));
        $this->assertEquals([['sku' => 'NPK-50', 'lot' => 'L1', 'expiry' => null, 'quantity' => 20], ['sku' => 'RICE-25', 'lot' => 'R7', 'expiry' => null, 'quantity' => 10]], $bundle->json('stock'));
    }
}

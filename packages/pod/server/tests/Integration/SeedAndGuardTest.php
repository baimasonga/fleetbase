<?php

namespace Fleetbase\Pod\Tests\Integration;

use Fleetbase\Pod\Models\AdminArea;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class SeedAndGuardTest extends IntegrationTestCase
{
    public function testSeedsSierraLeoneAndIsSafeToRunAgain(): void
    {
        $this->artisan('pod:seed-admin-areas')
            ->expectsOutputToContain('Sierra Leone: 1504 areas in the file, 1504 added, 0 updated.')
            ->assertSuccessful();

        $this->assertSame(16, AdminArea::where('country', 'SL')->where('level', 2)->count());
        $this->assertSame('Falaba', AdminArea::where('pcode', 'SL0206')->value('name'));

        $this->artisan('pod:seed-admin-areas', ['country' => 'SL'])
            ->expectsOutputToContain('0 added, 0 updated')
            ->assertSuccessful();
        $this->assertSame(1504, AdminArea::count());
    }

    public function testAKeptLocalCorrectionIsReportedNotLost(): void
    {
        $this->artisan('pod:seed-admin-areas')->assertSuccessful();
        AdminArea::create(['country' => 'SL', 'level' => 4, 'pcode' => 'SL01019999', 'name' => 'New Section', 'parent_pcode' => 'SL010101']);

        $this->artisan('pod:seed-admin-areas')
            ->expectsOutputToContain('1 areas in the database are not in the file and were kept: SL01019999')
            ->assertSuccessful();
        $this->assertTrue(AdminArea::where('pcode', 'SL01019999')->exists());
    }

    public function testTheAdminAreaApiListsDistrictsAndChiefdoms(): void
    {
        $this->artisan('pod:seed-admin-areas')->assertSuccessful();

        $districts = $this->asCompany()->getJson('int/v1/pod/admin-areas?level=2');
        $districts->assertOk();
        $this->assertCount(16, $districts->json('areas'));
        $this->assertSame('Chiefdom', $districts->json('admin_levels.3'));

        $kailahun = $this->asCompany()->getJson('int/v1/pod/admin-areas?parent=SL0101')->json('areas');
        $this->assertCount(14, $kailahun);

        $countries = $this->asCompany()->getJson('int/v1/pod/countries');
        $this->assertSame('SL', $countries->json('default'));
        $this->assertSame('+232', $countries->json('countries.0.dial_code'));
    }

    // --- Proof guard on the Fleet-Ops public API -------------------------------------------

    private function order(bool $podRequired = true): array
    {
        $order = ['uuid' => (string) Str::uuid(), 'public_id' => 'order_' . Str::lower(Str::random(6)), 'company_uuid' => self::COMPANY, 'status' => 'started', 'pod_required' => $podRequired, 'pod_method' => 'signature'];
        DB::table('orders')->insert($order);

        return $order;
    }

    private function proofFor(array $order): string
    {
        $id = 'proof_' . Str::lower(Str::random(6));
        DB::table('proofs')->insert(['uuid' => (string) Str::uuid(), 'public_id' => $id, 'order_uuid' => $order['uuid']]);

        return $id;
    }

    private function complete(array $order, ?string $proof = null)
    {
        return $this->asCompany()->postJson("v1/orders/{$order['public_id']}/update-activity", array_filter([
            'activity' => ['code' => 'completed', 'complete' => true],
            'proof'    => $proof,
        ]));
    }

    public function testEnforceModeRefusesCompletionWithoutProof(): void
    {
        config(['pod.guard.mode' => 'enforce']);
        $order = $this->order();

        $this->complete($order)
            ->assertStatus(422)
            ->assertJson(['error' => 'proof_required', 'missing' => [['type' => 'signature', 'subject' => $order['public_id']]]]);
    }

    public function testEnforceModeAcceptsTheOrdersOwnProofOnly(): void
    {
        config(['pod.guard.mode' => 'enforce']);
        $order = $this->order();
        $other = $this->order();

        $this->complete($order, $this->proofFor($other))->assertStatus(422);
        $this->complete($order, 'proof_doesnotexist')->assertStatus(422);
        $this->complete($order, $this->proofFor($order))->assertOk()->assertJson(['updated' => $order['public_id']]);
    }

    public function testStepsBeforeCompletionAndOrdersWithoutPodPass(): void
    {
        config(['pod.guard.mode' => 'enforce']);

        $order = $this->order();
        $this->asCompany()->postJson("v1/orders/{$order['public_id']}/update-activity", ['activity' => ['code' => 'enroute']])->assertOk();

        $this->complete($this->order(false))->assertOk();
    }

    public function testAnActivityThatRequiresPodIsGuardedEvenBeforeCompletion(): void
    {
        config(['pod.guard.mode' => 'enforce']);
        $order = $this->order(false);

        $this->asCompany()->postJson("v1/orders/{$order['public_id']}/update-activity", ['activity' => ['code' => 'picked_up', 'require_pod' => true]])
            ->assertStatus(422);
    }

    public function testLogModeAllowsButLogs(): void
    {
        config(['pod.guard.mode' => 'log']);
        $logger = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('driver')->andReturn($logger);
        $order = $this->order();

        $this->complete($order)->assertOk();

        $logger->shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'without required proof') && $context['decision'] === 'warn' && $context['order'] === $order['public_id']);
    }

    public function testOffModeDoesNothing(): void
    {
        config(['pod.guard.mode' => 'off']);

        $this->complete($this->order())->assertOk();
    }

    public function testAnotherCompanysOrderIsLeftToFleetOps(): void
    {
        config(['pod.guard.mode' => 'enforce']);
        $order = $this->order();

        // The guard doesn't find the order in this company, so Fleet-Ops answers (its own 404 in production).
        $this->asCompany(self::OTHER_COMPANY)->postJson("v1/orders/{$order['public_id']}/update-activity", ['activity' => ['code' => 'completed', 'complete' => true]])
            ->assertOk();
    }
}

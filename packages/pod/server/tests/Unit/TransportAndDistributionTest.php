<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Distribution\AgeBands;
use Fleetbase\Pod\Support\Distribution\EventReportRenderer;
use Fleetbase\Pod\Support\Distribution\HandoverPolicy;
use Fleetbase\Pod\Support\Geo\Distance;
use Fleetbase\Pod\Support\Seal;
use Fleetbase\Pod\Support\Transport\ReceiptCheck;
use Fleetbase\Pod\Support\Transport\SealCheck;
use Fleetbase\Pod\Tests\TestCase;

final class TransportAndDistributionTest extends TestCase
{
    public function testSealsThatMatchAreIntact(): void
    {
        $result = SealCheck::compare(['SL-00123', 'SL-00124'], [['number' => 'sl-00124', 'intact' => true], ['number' => 'SL 00123', 'intact' => true]]);

        $this->assertSame(SealCheck::INTACT, $result['status'], 'case and spaces ignored');
    }

    public function testFindsBrokenMissingAndReplacedSeals(): void
    {
        $this->assertSame(SealCheck::BROKEN, SealCheck::compare(['A1', 'A2'], [['number' => 'A1', 'intact' => true], ['number' => 'A2', 'intact' => false]])['status']);

        $missing = SealCheck::compare(['A1', 'A2'], [['number' => 'A1', 'intact' => true]]);
        $this->assertSame(SealCheck::MISSING, $missing['status']);
        $this->assertSame(['A2'], $missing['missing']);

        $replaced = SealCheck::compare(['A1'], [['number' => 'B9', 'intact' => true]]);
        $this->assertSame(SealCheck::MISSING, $replaced['status'], 'the original seal is gone');
        $this->assertSame(['B9'], $replaced['unexpected']);

        $this->assertSame(SealCheck::MISMATCH, SealCheck::compare(['A1'], [['number' => 'A1', 'intact' => true], ['number' => 'B9', 'intact' => true]])['status']);
        $this->assertSame(SealCheck::NONE, SealCheck::compare([], [])['status']);
    }

    public function testComparesWhatWasLoadedWithWhatArrived(): void
    {
        $lines = ReceiptCheck::compare(
            [['sku' => 'NPK-50', 'lot' => 'L1', 'loaded' => 100], ['sku' => 'RICE-25', 'lot' => 'R7', 'loaded' => 50]],
            [['sku' => 'NPK-50', 'lot' => 'L1', 'good' => 95, 'damaged' => 3], ['sku' => 'RICE-25', 'lot' => 'R7', 'good' => 51], ['sku' => 'UREA-50', 'lot' => 'U2', 'good' => 4]]
        );

        $bySku = array_column($lines, null, 'sku');
        $this->assertSame([2.0, 'short_delivered'], [$bySku['NPK-50']['short'], $bySku['NPK-50']['reason']]);
        $this->assertSame([-1.0, 'excess'], [$bySku['RICE-25']['short'], $bySku['RICE-25']['reason']]);
        $this->assertSame([-4.0, 'excess'], [$bySku['UREA-50']['short'], $bySku['UREA-50']['reason']], 'arrived but never loaded');
    }

    public function testAnItemNotCountedAtReceiptIsAllMissing(): void
    {
        $lines = ReceiptCheck::compare([['sku' => 'NPK-50', 'lot' => 'L1', 'loaded' => 10]], []);

        $this->assertSame(10.0, $lines[0]['short']);
    }

    public function testTheHandoverPolicyAddsWitnessAndSafetyWhenNeeded(): void
    {
        $plain = HandoverPolicy::build(null, false, 'L2', false);
        $this->assertSame(HandoverPolicy::DEFAULT, $plain);

        $proxy = HandoverPolicy::build(null, true, 'L2', false);
        $this->assertContains(['type' => 'witness_signature'], $proxy['all']);

        $l1 = HandoverPolicy::build(null, false, 'L1', true);
        $this->assertContains(['type' => 'witness_signature'], $l1['all']);
        $this->assertContains(['type' => 'safety_ack'], $l1['all']);

        $custom = HandoverPolicy::build(['type' => 'signature'], false, 'L3', false);
        $this->assertSame(['type' => 'signature'], $custom);
    }

    public function testCountsPeopleBySexAndAgeBand(): void
    {
        $totals = AgeBands::totals([
            ['sex' => 'female', 'birth_year' => 2005], // 22
            ['sex' => 'female', 'birth_year' => 1991], // 36
            ['sex' => 'male', 'birth_year' => 1995],   // 32
            ['sex' => 'male', 'birth_year' => 1960],   // 67
            ['sex' => null, 'birth_year' => null],
        ], 2027);

        $this->assertSame(5, $totals['total']);
        $this->assertSame(['female' => 2, 'male' => 2, 'unknown' => 1], $totals['by_sex']);
        $this->assertSame(['under_15' => 0, '15_24' => 1, '25_35' => 1, '36_59' => 1, '60_plus' => 1, 'unknown' => 1], $totals['by_age_band']);
        $this->assertSame(2, $totals['youth_15_35']);
    }

    public function testTheSealIgnoresKeyOrderButNotContent(): void
    {
        $a = Seal::hash(['b' => 1, 'a' => ['y' => 2.0, 'x' => [1, 2]]]);
        $b = Seal::hash(['a' => ['x' => [1, 2], 'y' => 2.0], 'b' => 1]);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, Seal::hash(['a' => ['x' => [2, 1], 'y' => 2.0], 'b' => 1]), 'list order matters');
        $this->assertNotSame($a, Seal::hash(['a' => ['x' => [1, 2], 'y' => 2.5], 'b' => 1]));
    }

    public function testDistance(): void
    {
        // Kenema to Bo is roughly 64 km in a straight line.
        $this->assertEqualsWithDelta(64000, Distance::metres(7.8767, -11.1875, 7.9647, -11.7383), 3000);
        $this->assertSame(0.0, Distance::metres(8.0, -11.0, 8.0, -11.0));
    }

    public function testTheReportShowsTotalsReconciliationAndEscapedNames(): void
    {
        $html = (new EventReportRenderer())->render([
            'programme'      => ['name' => 'Rice Support', 'season' => '2027'],
            'event'          => ['public_id' => 'event_abc', 'name' => 'Dea DP day 1', 'kind' => 'point', 'status' => 'closed', 'opened_at' => '12/03/2027 08:00', 'closed_at' => '12/03/2027 16:30', 'seal_hash' => str_repeat('a', 64)],
            'site'           => ['name' => 'Baiwalla DP', 'place' => 'Baiwalla, Dea, Kailahun'],
            'totals'         => ['assigned' => 3, 'served' => ['total' => 2, 'by_sex' => ['female' => 1, 'male' => 1], 'by_age_band' => ['25_35' => 1, '36_59' => 1], 'youth_15_35' => 1], 'partial' => 0, 'failed_attempts' => 0, 'proxy' => 0, 'flagged' => 0],
            'reconciliation' => ['balanced' => true, 'lines' => [['sku' => 'NPK-50', 'lot' => 'L1', 'opening' => 10, 'handed_over' => 4, 'closing' => 6, 'discrepancies' => 0, 'variance' => 0, 'balanced' => true]]],
            'discrepancies'  => [],
            'witnesses'      => ['open' => [['name' => 'Chief Musa', 'role' => 'chief', 'signature' => null]]],
            'handovers'      => [['time' => '09:10', 'name' => '<Aminata>', 'beneficiary' => 'beneficiary_x', 'sex' => 'F', 'received_by' => 'self', 'recipient_name' => 'Aminata', 'identity_level' => 'L2', 'lines' => [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2]], 'outcome' => 'delivered', 'failure_reason' => null, 'flags' => [], 'seal_hash' => str_repeat('b', 64)]],
            'generated_at'   => '12/03/2027 16:31',
        ]);

        $this->assertStringContainsString('Balanced: every unit is accounted for.', $html);
        $this->assertStringContainsString('&lt;Aminata&gt;', $html);
        $this->assertStringContainsString('2 NPK-50 (L1)', $html);
        $this->assertStringContainsString('Chief Musa', $html);
        $this->assertStringContainsString(str_repeat('a', 64), $html);
    }
}

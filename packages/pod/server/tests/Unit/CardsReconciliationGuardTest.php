<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Cards\ProgrammeCard;
use Fleetbase\Pod\Support\Guard\ProofGuard;
use Fleetbase\Pod\Support\Reconciliation\StockReconciler;
use Fleetbase\Pod\Tests\TestCase;

final class CardsReconciliationGuardTest extends TestCase
{
    private function key(string $programme = 'programme_a1b2c3'): string
    {
        return ProgrammeCard::programmeKey('installation-secret', $programme);
    }

    public function testACardVerifiesOfflineWithTheProgrammeKey(): void
    {
        $code = ProgrammeCard::issue($this->key(), 'programme_a1b2c3', 'beneficiary_x9y8z7');

        $this->assertMatchesRegularExpression('/^FBP1\.programme_a1b2c3\.beneficiary_x9y8z7\.1\.[A-Z2-7]{26}$/', $code);

        $result = ProgrammeCard::verify($code, $this->key(), 'programme_a1b2c3', ['beneficiary_x9y8z7' => 1]);
        $this->assertTrue($result->valid);
        $this->assertSame('beneficiary_x9y8z7', $result->beneficiaryId);
    }

    public function testAnEditedCardIsRefused(): void
    {
        $code   = ProgrammeCard::issue($this->key(), 'programme_a1b2c3', 'beneficiary_x9y8z7');
        $forged = str_replace('beneficiary_x9y8z7', 'beneficiary_aaaaaa', $code);

        $this->assertSame('bad_signature', ProgrammeCard::verify($forged, $this->key())->reason);
    }

    public function testACardFromAnotherProgrammeIsRefused(): void
    {
        $other = ProgrammeCard::issue($this->key('programme_other'), 'programme_other', 'beneficiary_x9y8z7');

        $this->assertSame('bad_signature', ProgrammeCard::verify($other, $this->key())->reason, 'signed with a different key');
        $this->assertSame('wrong_programme', ProgrammeCard::verify($other, $this->key('programme_other'), 'programme_a1b2c3')->reason);
    }

    public function testReissuingACardRevokesTheOldOne(): void
    {
        $old = ProgrammeCard::issue($this->key(), 'programme_a1b2c3', 'beneficiary_x9y8z7', 1);
        $new = ProgrammeCard::issue($this->key(), 'programme_a1b2c3', 'beneficiary_x9y8z7', 2);

        $manifest = ['beneficiary_x9y8z7' => 2];
        $this->assertSame('revoked', ProgrammeCard::verify($old, $this->key(), null, $manifest)->reason);
        $this->assertTrue(ProgrammeCard::verify($new, $this->key(), null, $manifest)->valid);
        $this->assertSame('not_in_manifest', ProgrammeCard::verify($new, $this->key(), null, ['someone_else' => 1])->reason);
    }

    public function testAcceptsATypedCodeWithALowerCaseSignature(): void
    {
        $code  = ProgrammeCard::issue($this->key(), 'programme_a1b2c3', 'beneficiary_x9y8z7');
        $parts = explode('.', $code);
        $parts[4] = strtolower($parts[4]);

        $this->assertTrue(ProgrammeCard::verify(' ' . implode('.', $parts) . ' ', $this->key())->valid);
    }

    public function testRejectsGarbage(): void
    {
        foreach (['', 'hello', 'FBP1.a.b.c.d', 'FBP1.a.b.0.SIG', 'XYZ1.a.b.1.SIG'] as $code) {
            $this->assertSame('malformed', ProgrammeCard::verify($code, $this->key())->reason, $code);
        }
    }

    public function testAnEventBalancesWhenEveryBagIsAccountedFor(): void
    {
        $result = (new StockReconciler())->reconcile(
            opening: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 100], ['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 50]],
            handedOver: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 60], ['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 30], ['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 48]],
            closing: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 8], ['sku' => 'RICE-25', 'lot' => 'R7', 'quantity' => 2]],
            discrepancies: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 2]],
        );

        $this->assertTrue($result->balanced);
        $this->assertSame([], $result->unbalanced());
    }

    public function testAMissingBagBlocksTheClose(): void
    {
        $result = (new StockReconciler())->reconcile(
            opening: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 100]],
            handedOver: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 95]],
            closing: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 4]],
        );

        $this->assertFalse($result->balanced);
        $this->assertSame(1.0, $result->unbalanced()[0]['variance']);
    }

    public function testLotsAreReconciledSeparately(): void
    {
        $result = (new StockReconciler())->reconcile(
            opening: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 10], ['sku' => 'NPK-50', 'lot' => 'L2', 'quantity' => 10]],
            handedOver: [['sku' => 'NPK-50', 'lot' => 'L1', 'quantity' => 20]],
            closing: [],
        );

        $this->assertFalse($result->balanced, 'handing over lot L2 as L1 is a variance on both lots');
        $this->assertCount(2, $result->unbalanced());
    }

    public function testRejectsNegativeQuantities(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new StockReconciler())->reconcile([['sku' => 'X', 'quantity' => -1]], [], []);
    }

    public function testTheGuardOnlyStopsUpdatesThatNeedProofAndHaveNone(): void
    {
        $this->assertTrue(ProofGuard::requiresProof(true, false, false), 'the activity requires proof');
        $this->assertTrue(ProofGuard::requiresProof(false, true, true), 'completing an order that requires proof');
        $this->assertFalse(ProofGuard::requiresProof(false, true, false));
        $this->assertFalse(ProofGuard::requiresProof(false, false, true), 'a step before completion');

        $enforce = new ProofGuard(ProofGuard::ENFORCE);
        $this->assertSame(ProofGuard::DENY, $enforce->decide(true, false));
        $this->assertSame(ProofGuard::ALLOW, $enforce->decide(true, true));
        $this->assertSame(ProofGuard::ALLOW, $enforce->decide(false, false));

        $this->assertSame(ProofGuard::WARN, (new ProofGuard(ProofGuard::LOG))->decide(true, false));
        $this->assertSame(ProofGuard::ALLOW, (new ProofGuard(ProofGuard::OFF))->decide(true, false));
    }

    public function testTheGuardRejectsAnUnknownMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProofGuard('strict');
    }
}

<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Support\Policy\IdentityLevels;
use Fleetbase\Pod\Support\Policy\PolicyResolver;
use Fleetbase\Pod\Support\Policy\RequirementTree;
use Fleetbase\Pod\Tests\TestCase;

final class PolicyTest extends TestCase
{
    /**
     * The default farmer handover policy (AGRI-INPUTS.md §8).
     */
    private const HANDOVER = ['all' => [
        ['type' => 'beneficiary_id', 'min_level' => 'L1'],
        ['type' => 'line_quantities', 'match' => 'entitlement'],
        ['type' => 'lot_scan', 'coverage' => 'all_lines'],
        ['type' => 'photo', 'min' => 1],
        ['any' => [['type' => 'signature'], ['type' => 'mark'], ['type' => 'thumbprint_image']]],
    ]];

    private const LINES = ['lines' => [
        ['id' => 'seed', 'entitled' => 1],
        ['id' => 'npk', 'entitled' => 2],
    ]];

    private function completeEvidence(): array
    {
        return [
            ['type' => 'beneficiary_id', 'level' => 'L2', 'method' => 'card_qr'],
            ['type' => 'line_quantities', 'line' => 'seed', 'quantity' => 1],
            ['type' => 'line_quantities', 'line' => 'npk', 'quantity' => 2],
            ['type' => 'lot_scan', 'line' => 'seed', 'lot' => 'L-001'],
            ['type' => 'lot_scan', 'line' => 'npk', 'lot' => 'L-002'],
            ['type' => 'photo'],
            ['type' => 'mark'],
        ];
    }

    public function testACompleteHandoverMeetsTheDefaultPolicy(): void
    {
        $result = (new RequirementTree())->evaluate(self::HANDOVER, $this->completeEvidence(), self::LINES);

        $this->assertTrue($result->satisfied);
        $this->assertSame([], $result->missing);
    }

    public function testReportsEveryMissingRequirement(): void
    {
        $evidence = array_values(array_filter($this->completeEvidence(), fn ($e) => !in_array($e['type'], ['photo', 'mark'], true)));

        $result = (new RequirementTree())->evaluate(self::HANDOVER, $evidence, self::LINES);

        $this->assertFalse($result->satisfied);
        $this->assertSame([
            ['type' => 'photo', 'min' => 1],
            ['any' => [['type' => 'signature'], ['type' => 'mark'], ['type' => 'thumbprint_image']]],
        ], $result->missing);
    }

    public function testRefusesMoreThanTheEntitlement(): void
    {
        $evidence    = $this->completeEvidence();
        $evidence[2] = ['type' => 'line_quantities', 'line' => 'npk', 'quantity' => 3];

        $this->assertFalse((new RequirementTree())->evaluate(self::HANDOVER, $evidence, self::LINES)->satisfied);
    }

    public function testNeedsALotScanForEveryLine(): void
    {
        $evidence = array_values(array_filter($this->completeEvidence(), fn ($e) => !($e['type'] === 'lot_scan' && $e['line'] === 'npk')));

        $result = (new RequirementTree())->evaluate(self::HANDOVER, $evidence, self::LINES);

        $this->assertSame([['type' => 'lot_scan', 'coverage' => 'all_lines']], $result->missing);
    }

    public function testChecksTheIdentityLevel(): void
    {
        $tree = ['type' => 'beneficiary_id', 'min_level' => 'L2'];

        $this->assertFalse((new RequirementTree())->evaluate($tree, [['type' => 'beneficiary_id', 'level' => 'L1']])->satisfied);
        $this->assertTrue((new RequirementTree())->evaluate($tree, [['type' => 'beneficiary_id', 'level' => 'L3']])->satisfied);
    }

    public function testCanRequireTheSignersName(): void
    {
        $tree = ['type' => 'signature', 'require_name' => true];

        $this->assertFalse((new RequirementTree())->evaluate($tree, [['type' => 'signature']])->satisfied);
        $this->assertTrue((new RequirementTree())->evaluate($tree, [['type' => 'signature', 'name' => 'Aminata Kamara']])->satisfied);
    }

    public function testRejectsMalformedTrees(): void
    {
        foreach ([['all' => []], ['all' => [['type' => 'photo']], 'any' => []], ['min' => 2], ['type' => 'photo', 'min' => 0]] as $tree) {
            try {
                RequirementTree::assertValid($tree);
                $this->fail('Accepted ' . json_encode($tree));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCombinesTreesFlatly(): void
    {
        $this->assertSame(
            ['all' => [['type' => 'signature'], ['type' => 'photo'], ['type' => 'otp']]],
            RequirementTree::allOf(['type' => 'signature'], ['all' => [['type' => 'photo'], ['type' => 'otp']]])
        );
        $this->assertSame(['type' => 'photo'], RequirementTree::allOf(['type' => 'photo'], []));
    }

    public function testTheHighestPriorityMatchingPolicyWins(): void
    {
        $candidates = [
            ['id' => 'default', 'priority' => 0, 'scope_type' => null, 'requirements' => ['type' => 'signature']],
            ['id' => 'programme', 'priority' => 10, 'scope_type' => 'programme', 'scope_id' => 'prog_1', 'requirements' => self::HANDOVER],
            ['id' => 'other-programme', 'priority' => 50, 'scope_type' => 'programme', 'scope_id' => 'prog_2', 'requirements' => ['type' => 'otp']],
            ['id' => 'inactive', 'priority' => 99, 'scope_type' => null, 'active' => false, 'requirements' => ['type' => 'otp']],
        ];

        $resolved = (new PolicyResolver())->resolve($candidates, ['programme' => 'prog_1']);
        $this->assertSame('programme', $resolved['policy']['id']);

        $resolved = (new PolicyResolver())->resolve($candidates, ['programme' => 'prog_3']);
        $this->assertSame('default', $resolved['policy']['id']);
    }

    public function testOnATieTheMoreSpecificScopeWins(): void
    {
        $candidates = [
            ['id' => 'zone', 'priority' => 5, 'scope_type' => 'zone', 'scope_id' => 'z1', 'requirements' => ['type' => 'photo']],
            ['id' => 'customer', 'priority' => 5, 'scope_type' => 'customer', 'scope_id' => 'c1', 'requirements' => ['type' => 'otp']],
        ];

        $resolved = (new PolicyResolver())->resolve($candidates, ['zone' => ['z1', 'z2'], 'customer' => 'c1']);

        $this->assertSame('customer', $resolved['policy']['id']);
    }

    public function testTheLegacyFleetOpsSettingIsAlwaysAdded(): void
    {
        $resolver = new PolicyResolver();

        $this->assertSame(['type' => 'signature'], $resolver->resolve([], [], true, 'signature')['requirements']);
        $this->assertNull($resolver->resolve([], [], false, 'signature')['requirements']);

        $withPolicy = $resolver->resolve([['requirements' => ['type' => 'photo']]], [], true, 'scan')['requirements'];
        $this->assertSame(['all' => [['type' => 'qr_scan'], ['type' => 'photo']]], $withPolicy);

        $unknownMethod = $resolver->resolve([], [], true, null)['requirements'];
        $this->assertArrayHasKey('any', $unknownMethod);
    }

    public function testWorksOutIdentityLevels(): void
    {
        $this->assertSame('L3', IdentityLevels::determine(true, true, true, true, false));
        $this->assertSame('L2', IdentityLevels::determine(true, true, false, true, false));
        $this->assertSame('L1', IdentityLevels::determine(false, false, false, true, true));
        $this->assertNull(IdentityLevels::determine(false, false, false, true, false));
        $this->assertNull(IdentityLevels::determine(true, false, true, false, false), 'a card without a face match is not enough');
    }
}

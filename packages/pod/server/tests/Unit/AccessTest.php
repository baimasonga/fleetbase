<?php

namespace Fleetbase\Pod\Tests\Unit;

use Fleetbase\Pod\Auth\Schemas\Pod;
use Fleetbase\Pod\Support\Access\PermissionSet;
use Fleetbase\Pod\Tests\TestCase;

/**
 * The Invendis roles: what each one can and cannot do.
 */
final class AccessTest extends TestCase
{
    private static function role(string $name): PermissionSet
    {
        return PermissionSet::forRoles(new Pod(), [$name]);
    }

    public function testEveryPermissionThePoliciesGiveExists(): void
    {
        $schema  = new Pod();
        $defined = PermissionSet::defined($schema);
        foreach ($schema->policies as $policy) {
            foreach ($policy['permissions'] as $permission) {
                $name = 'pod ' . $permission;
                $this->assertContains($name, $defined, "{$policy['name']} gives {$name}, which the schema does not define");
            }
        }
        $policies = array_column($schema->policies, 'name');
        foreach ($schema->roles as $role) {
            $this->assertNotEmpty($role['policies']);
            foreach ($role['policies'] as $policy) {
                $this->assertContains($policy, $policies, "{$role['name']} uses an unknown policy");
            }
        }
    }

    public function testWildcardsWorkTheWayFleetbaseChecksThem(): void
    {
        $set = new PermissionSet(['pod * farmer', 'pod view report']);

        $this->assertTrue($set->allows('pod import farmer'));
        $this->assertTrue($set->allows('pod view report'));
        $this->assertFalse($set->allows('pod export report'));
        $this->assertFalse($set->allows('fleet-ops import farmer'));
        $this->assertTrue((new PermissionSet(['pod *']))->allows('pod resolve conflict'));
        $this->assertTrue(PermissionSet::forRoles(new Pod(), ['Administrator'])->allows('pod close trip'));
        $this->assertFalse(PermissionSet::forRoles(new Pod(), [])->allows('pod list programme'));
    }

    public function testAFieldOfficerHandsOverButApprovesNothing(): void
    {
        $officer = self::role('Invendis Field Officer');

        foreach (['sync field', 'open distribution', 'close distribution', 'create handover', 'create discrepancy', 'check card'] as $p) {
            $this->assertTrue($officer->allows("pod {$p}"), $p);
        }
        foreach (['resolve conflict', 'list conflict', 'resolve discrepancy', 'import farmer', 'verify farmer', 'generate entitlement', 'print card', 'create programme', 'receive stock', 'load trip'] as $p) {
            $this->assertFalse($officer->allows("pod {$p}"), $p);
        }
    }

    public function testTheStorekeeperMovesStockButDoesNotExplainAwayShortages(): void
    {
        $storekeeper = self::role('Invendis Storekeeper');

        foreach (['receive stock', 'create trip', 'load trip', 'receive trip', 'close trip', 'create discrepancy'] as $p) {
            $this->assertTrue($storekeeper->allows("pod {$p}"), $p);
        }
        foreach (['resolve discrepancy', 'resolve conflict', 'create handover', 'sync field'] as $p) {
            $this->assertFalse($storekeeper->allows("pod {$p}"), $p);
        }
    }

    public function testATransporterOnlyHasTheirOwnTrips(): void
    {
        $transporter = self::role('Invendis Transporter');

        $this->assertTrue($transporter->allows('pod depart own-trip'));
        $this->assertTrue($transporter->allows('pod arrive own-trip'));
        foreach (['list trip', 'view trip', 'depart trip', 'receive trip', 'list farmer', 'list programme', 'sync field'] as $p) {
            $this->assertFalse($transporter->allows("pod {$p}"), $p);
        }
    }

    public function testMonitorsReadEverythingAndChangeNothing(): void
    {
        $monitor = self::role('Invendis Monitor');

        foreach (['list programme', 'list farmer', 'list trip', 'list handover', 'list conflict', 'view media', 'view report', 'export report'] as $p) {
            $this->assertTrue($monitor->allows("pod {$p}"), $p);
        }
        foreach ($monitor->names() as $name) {
            $action = explode(' ', $name)[1];
            $this->assertContains($action, ['see', 'list', 'view', 'export'], "a monitor can {$name}");
        }
    }

    public function testSupervisorsReviewAndManagersSetUp(): void
    {
        $supervisor = self::role('Invendis Supervisor');
        $this->assertTrue($supervisor->allows('pod resolve conflict'));
        $this->assertTrue($supervisor->allows('pod resolve discrepancy'));
        $this->assertTrue($supervisor->allows('pod create handover'));
        $this->assertFalse($supervisor->allows('pod import farmer'));

        $manager = self::role('Invendis Programme Manager');
        foreach (['import farmer', 'verify farmer', 'generate entitlement', 'print card', 'create distribution', 'assign distribution', 'resolve conflict', 'load trip'] as $p) {
            $this->assertTrue($manager->allows("pod {$p}"), $p);
        }
        $this->assertFalse($manager->allows('pod sync field'), 'managers set up and review; officers record');
    }
}

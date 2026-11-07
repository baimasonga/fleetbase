<?php

namespace Fleetbase\Pod\Auth\Schemas;

/**
 * Permissions, policies and roles for Proof of Delivery, in Fleetbase's
 * format. `php artisan fleetbase:create-permissions` creates them; an
 * administrator then gives each person a role in IAM (Users).
 *
 * Every permission is "pod <action> <resource>". Fleetbase also creates
 * create/update/delete/view/list/see for every resource, "pod * <resource>"
 * for each resource and "pod *" for everything.
 *
 * The roles follow how a programme runs (AGRI-INPUTS.md §5, REPORTING-AUDIT.md §2):
 * whoever records something is not the one who approves it, transporters
 * see only their own trips, and monitors and auditors can read everything
 * but change nothing.
 */
class Pod
{
    public string $name = 'pod';

    public string $policyName = 'PoD';

    public array $guards = ['sanctum'];

    public array $resources = [
        ['name' => 'programme', 'actions' => []],                                      // programmes, items, entitlement rules
        ['name' => 'farmer', 'actions' => ['import', 'verify', 'reject']],            // beneficiaries and Excel imports
        ['name' => 'entitlement', 'actions' => ['generate']],
        ['name' => 'card', 'actions' => ['print', 'reissue', 'check']],
        ['name' => 'site', 'actions' => []],
        ['name' => 'stock', 'actions' => ['receive']],
        ['name' => 'trip', 'actions' => ['load', 'depart', 'arrive', 'receive', 'close', 'cancel']],
        ['name' => 'own-trip', 'actions' => ['depart', 'arrive']],                     // a transporter's own trips only
        ['name' => 'distribution', 'actions' => ['assign', 'open', 'close', 'reassign']],
        ['name' => 'handover', 'actions' => []],                                       // handovers and door-to-door visits
        ['name' => 'discrepancy', 'actions' => ['resolve']],
        ['name' => 'field', 'actions' => ['sync']],                                    // the offline field app
        ['name' => 'conflict', 'actions' => ['resolve']],
        ['name' => 'media', 'actions' => []],                                          // photos and signatures
        ['name' => 'report', 'actions' => ['export']],
    ];

    public array $policies = [
        [
            'name'        => 'PodProgrammeSetup',
            'description' => 'Set up programmes: farmer lists, entitlements, cards, sites and distribution days.',
            'permissions' => [
                'see extension',
                '* programme', '* farmer', '* entitlement', '* card',
                'list site', 'view site', 'create site', 'update site',
                'list distribution', 'view distribution', 'create distribution', 'update distribution', 'assign distribution', 'reassign distribution',
                'list handover', 'view report', 'export report',
            ],
        ],
        [
            'name'        => 'PodFieldWork',
            'description' => 'Distribution days in the field app: open and close the day, hand over, record damaged or missing stock.',
            'permissions' => [
                'see extension',
                'sync field', 'list distribution', 'view distribution', 'open distribution', 'close distribution',
                'create handover', 'list handover', 'create discrepancy', 'list farmer', 'view farmer', 'check card',
            ],
        ],
        [
            'name'        => 'PodSupervision',
            'description' => 'Review records the server could not accept and resolve stock discrepancies. Not on records one made oneself.',
            'permissions' => [
                'see extension',
                'list conflict', 'view conflict', 'resolve conflict', 'resolve discrepancy', 'view media',
                'list distribution', 'view distribution', 'list handover', 'view report',
            ],
        ],
        [
            'name'        => 'PodStores',
            'description' => 'Stores and transport: receive supplier deliveries, load and receive trucks.',
            'permissions' => [
                'see extension',
                'list site', 'view site', 'receive stock', '* trip', 'create discrepancy', 'list programme', 'view programme',
            ],
        ],
        [
            'name'        => 'PodTransport',
            'description' => 'Hired or staff transporters: their own trips only, departure and arrival.',
            'permissions' => [
                'see extension',
                'list own-trip', 'view own-trip', 'depart own-trip', 'arrive own-trip',
            ],
        ],
        [
            'name'        => 'PodMonitoring',
            'description' => 'M&E officers, auditors and third-party monitors: read everything, change nothing.',
            'permissions' => [
                'see extension',
                'list programme', 'view programme', 'list farmer', 'view farmer', 'view entitlement', 'list site', 'view site',
                'list trip', 'view trip', 'list distribution', 'view distribution', 'list handover', 'list conflict', 'view conflict',
                'view media', 'view report', 'export report',
            ],
        ],
    ];

    public array $roles = [
        [
            'name'        => 'PoD Programme Manager',
            'description' => 'Runs a programme: farmer lists, entitlements, cards, stock, trips and distribution days, and reviews.',
            'policies'    => ['PodProgrammeSetup', 'PodSupervision', 'PodStores', 'PodMonitoring'],
        ],
        [
            'name'        => 'PoD Supervisor',
            'description' => 'Field supervisor: works distribution days and reviews other officers\' records.',
            'policies'    => ['PodFieldWork', 'PodSupervision', 'PodMonitoring'],
        ],
        [
            'name'        => 'PoD Field Officer',
            'description' => 'Hands over inputs to farmers with the field app.',
            'policies'    => ['PodFieldWork'],
        ],
        [
            'name'        => 'PoD Storekeeper',
            'description' => 'Receives, loads and dispatches stock at warehouses and stores.',
            'policies'    => ['PodStores'],
        ],
        [
            'name'        => 'PoD Transporter',
            'description' => 'Hired trucker or staff driver: sees and updates only the trips given to them.',
            'policies'    => ['PodTransport'],
        ],
        [
            'name'        => 'PoD Monitor',
            'description' => 'M&E officer, auditor or third-party monitor: read-only access, including photos and reports.',
            'policies'    => ['PodMonitoring'],
        ],
    ];
}

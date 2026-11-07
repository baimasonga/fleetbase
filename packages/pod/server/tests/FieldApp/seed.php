<?php
// Fresh database with one open distribution day, for the field app end-to-end test.
require __DIR__ . '/app.php';

@unlink(POD_E2E_DIR . '/e2e.sqlite');
$app = e2e_app();
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', ['--force' => true]);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('pod:seed-admin-areas');

use Fleetbase\Pod\Models\{Beneficiary, Programme};
use Fleetbase\Pod\Services\{CardService, DistributionService, EntitlementService, StockLedger};
use Fleetbase\Pod\Models\Site;

$company = '11111111-1111-1111-1111-111111111111';
session(['company' => $company]);

$programme = Programme::create(['company_uuid' => $company, 'name' => 'Rice Seed and Fertiliser Support', 'operator_type' => 'ngo', 'season' => '2027 Main Season', 'country' => 'SL', 'status' => 'active']);
$ent = $app->make(EntitlementService::class);
$ent->setItems($programme, [
    ['sku' => 'RICE-25', 'name' => 'Rice seed NERICA L19, 25 kg', 'category' => 'seed', 'unit' => 'bag'],
    ['sku' => 'NPK-50', 'name' => 'NPK 15-15-15, 50 kg', 'category' => 'fertiliser', 'unit' => 'bag'],
    ['sku' => 'HERB-1L', 'name' => 'Herbicide, 1 litre', 'category' => 'pesticide', 'unit' => 'bottle'],
]);
$programme->refresh();
$ent->setRules($programme, [['sku' => 'RICE-25', 'quantity' => 1], ['sku' => 'NPK-50', 'quantity' => 2], ['sku' => 'HERB-1L', 'quantity' => 1]]);

$dp = Site::create(['company_uuid' => $company, 'name' => 'Baiwalla Distribution Point', 'type' => 'distribution_point', 'country' => 'SL', 'admin_2' => 'SL0101', 'admin_3' => 'SL010101', 'village' => 'Baiwalla', 'latitude' => 7.9804, 'longitude' => -10.6360, 'active' => true]);
$stock = $app->make(StockLedger::class);
foreach ([['RICE-25', 'R7', 10, '2028-06-30'], ['NPK-50', 'L1', 20, null], ['HERB-1L', 'H3', 5, '2028-01-31'], ['NPK-50', 'OLD2', 3, '2024-01-31']] as [$sku, $lot, $qty, $exp]) {
    $stock->move(['company_uuid' => $company, 'programme_uuid' => $programme->uuid, 'holder_type' => 'site', 'holder_uuid' => $dp->uuid, 'sku' => $sku, 'lot' => $lot, 'expiry' => $exp, 'quantity' => $qty, 'reason' => 'received_from_supplier']);
}

$cards  = $app->make(CardService::class);
$codes  = [];
$people = [
    ['Aminata Kamara', 'female', 1990, ['trained_restricted_products' => '2026-02-01']],
    ['Isatu Koroma', 'female', 2001, []],
    ['Musa Bangura', 'male', 1962, []],
];
foreach ($people as [$name, $sex, $year, $extra]) {
    $b = Beneficiary::create($extra + ['company_uuid' => $company, 'programme_uuid' => $programme->uuid, 'name' => $name, 'sex' => $sex, 'birth_year' => $year, 'admin_1' => 'SL01', 'admin_2' => 'SL0101', 'admin_3' => 'SL010101', 'village' => 'Baiwalla', 'id_type' => 'voter_id', 'id_last4' => '6789', 'farmer_ref' => 'KLH-' . (100 + count($codes))]);
    $b = $cards->verify($b, 'community_validation', true);
    $codes[$name] = ['id' => $b->public_id, 'code' => $cards->code($b)];
}
$ent->generate($programme);

$dist  = $app->make(DistributionService::class);
$event = $dist->create($programme, ['kind' => 'point', 'site' => $dp->public_id, 'name' => 'Baiwalla day 1', 'scheduled_date' => '2027-03-12']);
$dist->assign($event, ['admin_3' => 'SL010101']);
// The day stays planned: it is opened from the phone, offline.

// A replaced card for the revoked-card check: Musa's first card.
$old = $codes['Musa Bangura']['code'];
$musa = Beneficiary::where('public_id', $codes['Musa Bangura']['id'])->first();
$cards->reissue($musa, 'lost');
$codes['Musa Bangura']['old_code'] = $old;
$codes['Musa Bangura']['code']     = $cards->code($musa->refresh());

// The QR for the fake camera: Isatu's card.
file_put_contents(POD_E2E_DIR . '/isatu-qr.png', \Fleetbase\Pod\Support\Cards\QrCode::png($codes['Isatu Koroma']['code'], 10));

file_put_contents(POD_E2E_DIR . '/seed.json', json_encode(['event' => $event->public_id, 'programme' => $programme->public_id, 'farmers' => $codes], JSON_PRETTY_PRINT));
echo "seeded {$event->public_id}\n";

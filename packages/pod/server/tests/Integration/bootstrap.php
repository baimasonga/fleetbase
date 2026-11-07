<?php

/*
 * Integration test bootstrap: the Laravel layer (migrations, models, import
 * service, proof guard middleware, routes) on Orchestra Testbench + SQLite.
 *
 * Needs a vendor directory with orchestra/testbench ^8, given in
 * POD_TEST_VENDOR. Fleetbase core and Fleet-Ops are replaced by the small
 * stand-ins in Stubs/ when they aren't installed.
 */

require __DIR__ . '/../bootstrap.php';

if (!class_exists(\Fleetbase\Models\Model::class)) {
    require __DIR__ . '/Stubs/fleetbase.php';
}

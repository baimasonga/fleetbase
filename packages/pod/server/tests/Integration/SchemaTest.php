<?php

namespace Fleetbase\Pod\Tests\Integration;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

/**
 * Every model built on Fleetbase's base model (which soft-deletes) must have a
 * deleted_at column, or every query on it fails. This has bitten twice.
 */
final class SchemaTest extends IntegrationTestCase
{
    public function testSoftDeletingModelsHaveADeletedAtColumn(): void
    {
        $checked = 0;
        foreach (glob(__DIR__ . '/../../src/Models/*.php') as $file) {
            $class = 'Fleetbase\\Pod\\Models\\' . basename($file, '.php');
            $model = new $class();
            if (!in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                continue;
            }
            $this->assertTrue(Schema::hasColumn($model->getTable(), 'deleted_at'), "{$class} soft-deletes but {$model->getTable()} has no deleted_at column.");
            $checked++;
        }

        $this->assertGreaterThan(5, $checked);
    }
}

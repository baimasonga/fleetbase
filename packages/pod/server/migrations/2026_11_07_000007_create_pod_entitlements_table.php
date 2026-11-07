<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What each beneficiary is entitled to, per item, for a programme
 * (AGRI-INPUTS.md §6). One row per beneficiary and item.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_entitlements', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36);
            $table->char('beneficiary_uuid', 36);
            $table->string('sku', 64);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 16);
            $table->decimal('collected_quantity', 12, 3)->default(0);
            $table->string('status', 32)->default('open'); // open | partially_collected | collected | reassigned | expired | cancelled
            $table->string('delivery_model', 32)->default('distribution_point'); // distribution_point | door_to_door
            $table->char('distribution_event_uuid', 36)->nullable();
            $table->char('route_order_uuid', 36)->nullable();
            $table->unsignedSmallInteger('rule_index')->nullable();
            $table->string('cancel_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(['beneficiary_uuid', 'sku']);
            $table->index(['programme_uuid', 'sku', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_entitlements');
    }
};

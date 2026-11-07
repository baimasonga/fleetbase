<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sites that hold stock (stores, distribution points, vehicles on door-to-door
 * routes) and an append-only stock ledger per item and lot
 * (AGRI-INPUTS.md §3, §4, §11).
 *
 * Stock on hand at a holder = the sum of its movements. A distribution event
 * holds its own stock while it is open, so a holder is a site or an event.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_sites', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->string('name');
            $table->string('type', 32); // central_warehouse | district_store | distribution_point | vehicle
            $table->char('country', 2)->default('SL');
            $table->string('admin_1', 32)->nullable();
            $table->string('admin_2', 32)->nullable();
            $table->string('admin_3', 32)->nullable();
            $table->string('admin_4', 32)->nullable();
            $table->string('village')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('geofence_radius_m')->nullable();
            $table->char('place_uuid', 36)->nullable();   // optional Fleet-Ops place
            $table->char('vehicle_uuid', 36)->nullable(); // optional Fleet-Ops vehicle, for type vehicle
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pod_stock_movements', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36)->nullable();
            $table->string('holder_type', 16);  // site | event
            $table->char('holder_uuid', 36);
            $table->string('sku', 64);
            $table->string('lot', 64)->default('');
            $table->date('expiry')->nullable();
            $table->decimal('quantity', 12, 3); // + in, - out
            $table->string('reason', 32);       // received_from_supplier | dispatched | received | to_event | from_event | handed_over | discrepancy | adjustment
            $table->string('reference_type', 32)->nullable(); // leg | event | handover | discrepancy
            $table->char('reference_uuid', 36)->nullable();
            $table->char('user_uuid', 36)->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['holder_type', 'holder_uuid', 'sku', 'lot']);
            $table->index(['reference_type', 'reference_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_stock_movements');
        Schema::dropIfExists('pod_sites');
    }
};

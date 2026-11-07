<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Truck trips between sites, with counts and seals at both ends
 * (AGRI-INPUTS.md §4, REPORTING-AUDIT.md AR-01, AR-02, AR-23).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_transport_legs', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36)->index();
            $table->char('origin_site_uuid', 36);
            $table->char('destination_site_uuid', 36);
            $table->char('order_uuid', 36)->nullable();    // optional Fleet-Ops order, for tracking
            $table->string('status', 32)->default('draft'); // draft | loaded | in_transit | arrived | received | closed | cancelled
            $table->string('transporter_kind', 16)->default('staff'); // staff | hired
            $table->string('transporter_name')->nullable();
            $table->string('transporter_phone', 20)->nullable();
            $table->char('vendor_uuid', 36)->nullable();
            $table->char('driver_uuid', 36)->nullable();
            $table->char('vehicle_uuid', 36)->nullable();
            $table->string('vehicle_plate', 32)->nullable();
            $table->string('waybill_no', 64)->nullable();
            $table->json('seals_loaded')->nullable();
            $table->json('seals_received')->nullable();
            $table->string('seal_status', 16)->nullable(); // intact | mismatch | broken | missing | none
            $table->boolean('restricted_segregated')->nullable(); // pesticides kept apart from seed, feed and food
            $table->unsignedInteger('expected_duration_min')->nullable();
            $table->timestamp('loaded_at')->nullable();
            $table->char('loaded_by_uuid', 36)->nullable();
            $table->timestamp('departed_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->char('received_by_uuid', 36)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->char('closed_by_uuid', 36)->nullable();
            $table->json('flags')->nullable();
            $table->text('notes')->nullable();
            $table->char('seal_hash', 64)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pod_leg_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->char('leg_uuid', 36)->index();
            $table->string('sku', 64);
            $table->string('lot', 64)->default('');
            $table->date('expiry')->nullable();
            $table->decimal('loaded_quantity', 12, 3);
            $table->decimal('received_good', 12, 3)->nullable();
            $table->decimal('received_damaged', 12, 3)->nullable();
            $table->string('condition_note')->nullable();
            $table->timestamps();

            $table->unique(['leg_uuid', 'sku', 'lot']);
        });

        Schema::create('pod_discrepancies', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36);
            $table->string('subject_type', 16); // leg | event
            $table->char('subject_uuid', 36);
            $table->string('sku', 64);
            $table->string('lot', 64)->default('');
            $table->decimal('quantity', 12, 3); // + missing or unusable, - more than expected
            $table->string('reason', 32);       // short_delivered | damaged | expired | wet | torn_bag | spill_leak | counterfeit_suspected | theft | seal_broken | excess | count_error | other
            $table->string('liable_party', 16)->default('unknown'); // transporter | store | officer | unknown
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable(); // file uuids
            $table->string('status', 16)->default('open'); // open | resolved
            $table->string('resolution', 32)->nullable();   // written_off | replaced | recovered | investigating
            $table->char('created_by_uuid', 36)->nullable();
            $table->char('resolved_by_uuid', 36)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_discrepancies');
        Schema::dropIfExists('pod_leg_lines');
        Schema::dropIfExists('pod_transport_legs');
    }
};

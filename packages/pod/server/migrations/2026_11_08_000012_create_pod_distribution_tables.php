<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Distribution days at a distribution point, and door-to-door routes, with
 * one handover record per farmer per attempt (AGRI-INPUTS.md §3, §6, §8, §11).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_distribution_events', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36)->index();
            $table->string('kind', 16)->default('point'); // point | route
            $table->char('site_uuid', 36);                // the distribution point, or the store a route loads from
            $table->string('name');
            $table->date('scheduled_date')->nullable();
            $table->string('status', 16)->default('planned'); // planned | open | closed | cancelled
            $table->timestamp('opened_at')->nullable();
            $table->char('opened_by_uuid', 36)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->char('closed_by_uuid', 36)->nullable();
            $table->json('opening_stock')->nullable();
            $table->json('closing_stock')->nullable();
            $table->json('witnesses')->nullable();
            $table->json('reconciliation')->nullable();
            $table->json('totals')->nullable();
            $table->char('seal_hash', 64)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('pod_handovers', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36);
            $table->char('event_uuid', 36)->index();
            $table->char('beneficiary_uuid', 36)->index();
            $table->string('client_handover_id', 64)->nullable();
            $table->string('outcome', 16);                 // delivered | partial | failed
            $table->string('failure_reason', 32)->nullable();
            $table->string('received_by', 24)->nullable(); // self | household_member | proxy
            $table->string('recipient_name')->nullable();
            $table->string('identity_level', 4)->nullable();
            $table->json('identity_checks')->nullable();
            $table->json('lines')->nullable();
            $table->json('evidence')->nullable();
            $table->json('flags')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->char('officer_uuid', 36)->nullable();
            $table->string('status', 16)->default('sealed'); // sealed | voided
            $table->string('void_reason')->nullable();
            $table->char('seal_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['company_uuid', 'client_handover_id']);
        });

        Schema::table('pod_entitlements', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('collected_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('pod_entitlements', fn (Blueprint $table) => $table->dropColumn('attempts'));
        Schema::dropIfExists('pod_handovers');
        Schema::dropIfExists('pod_distribution_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ids made on the phone for openings, closings and discrepancies recorded
 * offline, so a record sent twice is applied once.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pod_distribution_events', function (Blueprint $table) {
            $table->string('open_client_id', 64)->nullable()->after('opened_by_uuid');
            $table->string('close_client_id', 64)->nullable()->after('closed_by_uuid');
        });
        Schema::table('pod_discrepancies', function (Blueprint $table) {
            $table->string('client_id', 64)->nullable()->after('subject_uuid');
            $table->unique(['company_uuid', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pod_discrepancies', function (Blueprint $table) {
            $table->dropUnique(['company_uuid', 'client_id']);
            $table->dropColumn('client_id');
        });
        Schema::table('pod_distribution_events', function (Blueprint $table) {
            $table->dropColumn(['open_client_id', 'close_client_id']);
        });
    }
};

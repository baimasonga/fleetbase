<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * How a supervisor resolved a sync conflict, and the discrepancies it created.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pod_sync_conflicts', function (Blueprint $table) {
            $table->string('resolution', 32)->nullable()->after('status'); // written_off | recovered | reviewed
            $table->json('discrepancies')->nullable()->after('resolution_notes');
        });
    }

    public function down(): void
    {
        Schema::table('pod_sync_conflicts', fn (Blueprint $table) => $table->dropColumn(['resolution', 'discrepancies']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Livestock counts per species, for livestock input entitlements
 * (AGRI-INPUTS.md §10.1), e.g. {"cattle": 4, "goats": 10}.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pod_beneficiaries', function (Blueprint $table) {
            $table->json('livestock')->nullable()->after('crop');
        });
    }

    public function down(): void
    {
        Schema::table('pod_beneficiaries', function (Blueprint $table) {
            $table->dropColumn('livestock');
        });
    }
};

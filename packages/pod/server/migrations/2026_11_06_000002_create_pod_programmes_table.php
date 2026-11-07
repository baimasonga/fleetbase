<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Distribution programmes run by an NGO, project or government (AGRI-INPUTS.md §6).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_programmes', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->string('name');
            $table->string('operator_type', 32)->default('ngo'); // ngo | project | government
            $table->string('funder')->nullable();
            $table->string('season')->nullable();
            $table->char('country', 2)->default('SL');
            $table->string('status', 32)->default('draft'); // draft | active | closed
            $table->json('planting_windows')->nullable();   // per district or zone
            $table->json('rules')->nullable();              // delivery models, proxy, witness, audit thresholds...
            $table->json('meta')->nullable();
            $table->char('created_by_uuid', 36)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_programmes');
    }
};

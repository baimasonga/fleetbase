<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Official administrative areas per country (region, district, chiefdom,
 * section...), keyed by p-code. Shared reference data, not per company.
 * Seeded with `php artisan pod:seed-admin-areas SL`.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_admin_areas', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('country', 2);
            $table->unsignedTinyInteger('level');
            $table->string('level_label', 64)->nullable();
            $table->string('pcode', 32);
            $table->string('name');
            $table->string('source_name')->nullable();
            $table->string('parent_pcode', 32)->nullable();
            $table->decimal('center_lat', 10, 7)->nullable();
            $table->decimal('center_lon', 10, 7)->nullable();
            $table->json('review_flags')->nullable();
            $table->timestamps();

            $table->unique(['country', 'pcode']);
            $table->index(['country', 'level']);
            $table->index(['country', 'parent_pcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_admin_areas');
    }
};

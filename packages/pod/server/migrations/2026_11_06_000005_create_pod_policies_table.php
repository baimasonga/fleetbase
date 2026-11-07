<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Proof of delivery policies: what proof a stop or handover needs (DESIGN.md §5).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_policies', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->string('name');
            $table->string('scope_type', 32)->nullable(); // null = company default; programme | order_config | customer | service_area | zone
            $table->char('scope_uuid', 36)->nullable();
            $table->integer('priority')->default(0);
            $table->json('requirements');
            $table->unsignedInteger('geofence_radius_m')->default(500);
            $table->unsignedInteger('time_window_grace_min')->nullable();
            $table->string('offline_mode', 32)->default('allowed'); // allowed | allowed_with_fallback | online_only
            $table->json('offline_fallback')->nullable();
            $table->unsignedSmallInteger('max_attempts')->default(2);
            $table->unsignedSmallInteger('max_offline_hours')->default(12);
            $table->unsignedSmallInteger('max_media_delay_hours')->default(24);
            $table->boolean('active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['company_uuid', 'scope_type', 'scope_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_policies');
    }
};

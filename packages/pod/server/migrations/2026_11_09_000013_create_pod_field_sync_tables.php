<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Offline field app sync (DESIGN.md §6.6):
 *
 * pod_media           photos and signatures captured offline and uploaded later.
 *                     The handover records each file's SHA-256 when it is
 *                     captured; the upload must match it.
 * pod_sync_conflicts  handovers recorded offline that the server could not accept
 *                     (e.g. the farmer was served on another phone first). The
 *                     goods have already left, so these are kept for review,
 *                     never dropped.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_media', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->index();
            $table->string('client_media_id', 64);
            $table->char('sha256', 64);
            $table->string('kind', 24)->nullable(); // photo | signature | mark | witness_signature | thumbprint_image
            $table->string('mime', 64);
            $table->unsignedInteger('size');
            $table->string('disk', 64);
            $table->string('path');
            $table->char('user_uuid', 36)->nullable();
            $table->timestamps();

            $table->unique(['company_uuid', 'client_media_id']);
        });

        Schema::create('pod_sync_conflicts', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('event_uuid', 36)->nullable()->index();
            $table->string('client_handover_id', 64);
            $table->string('kind', 16); // handover | attempt
            $table->json('payload');
            $table->string('error', 64);
            $table->text('message');
            $table->json('details')->nullable();
            $table->string('status', 16)->default('open'); // open | resolved
            $table->text('resolution_notes')->nullable();
            $table->char('user_uuid', 36)->nullable();
            $table->char('resolved_by_uuid', 36)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['company_uuid', 'client_handover_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_sync_conflicts');
        Schema::dropIfExists('pod_media');
    }
};

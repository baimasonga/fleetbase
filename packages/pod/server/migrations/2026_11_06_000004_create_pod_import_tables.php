<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Beneficiary list imports: one batch per uploaded file, one row record per
 * spreadsheet row, so every beneficiary can be traced to its file and row,
 * and rejected rows stay in a fix-up list (AGRI-INPUTS.md §7.1).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_import_batches', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36)->index();
            $table->string('original_filename');
            $table->string('disk', 64);
            $table->string('path');
            $table->string('extension', 8);
            $table->char('file_hash', 64);
            $table->string('sheet')->nullable();
            $table->unsignedInteger('header_row')->default(1);
            $table->json('mapping')->nullable();
            $table->boolean('is_template')->default(false);
            $table->string('status', 32)->default('uploaded'); // uploaded | previewed | committed | failed
            $table->json('summary')->nullable();
            $table->json('issue_counts')->nullable();
            $table->char('created_by_uuid', 36)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->char('committed_by_uuid', 36)->nullable();
            $table->softDeletes(); // Fleetbase models use soft deletes
            $table->timestamps();
        });

        Schema::create('pod_import_rows', function (Blueprint $table) {
            $table->increments('id');
            $table->char('import_batch_uuid', 36);
            $table->unsignedInteger('row_number');
            $table->string('status', 32);              // accepted | merged | exists | review | rejected
            $table->string('decision', 32)->nullable(); // review rows: import | skip
            $table->json('data')->nullable();
            $table->json('issues')->nullable();
            $table->json('places')->nullable();
            $table->json('raw')->nullable();
            $table->unsignedInteger('duplicate_of_row')->nullable();
            $table->char('duplicate_of_beneficiary_uuid', 36)->nullable();
            $table->json('review')->nullable();
            $table->char('beneficiary_uuid', 36)->nullable();
            $table->char('decided_by_uuid', 36)->nullable();
            $table->timestamps();

            $table->unique(['import_batch_uuid', 'row_number']);
            $table->index(['import_batch_uuid', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_import_rows');
        Schema::dropIfExists('pod_import_batches');
    }
};

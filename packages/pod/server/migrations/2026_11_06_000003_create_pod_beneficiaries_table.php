<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Registered farmers (AGRI-INPUTS.md §6, §7).
 *
 * Privacy: ID documents are kept as type + last 4 characters, plus a keyed
 * hash used only to find duplicates. The full ID number is never stored.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_beneficiaries', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->string('public_id', 191)->nullable()->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36);
            $table->string('type', 32)->default('farmer'); // farmer | animal_health_worker
            $table->string('farmer_ref')->nullable();
            $table->string('name');
            $table->string('sex', 8)->nullable();           // male | female
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('phone', 20)->nullable();         // E.164
            $table->string('phone_extra')->nullable();
            $table->string('id_type', 32)->nullable();
            $table->string('id_last4', 4)->nullable();
            $table->char('id_hash', 64)->nullable();
            $table->string('admin_1', 32)->nullable();       // p-codes; labels come from the country profile
            $table->string('admin_2', 32)->nullable();
            $table->string('admin_3', 32)->nullable();
            $table->string('admin_4', 32)->nullable();
            $table->string('village')->nullable();
            $table->string('group_name')->nullable();
            $table->unsignedSmallInteger('household_size')->nullable();
            $table->string('farm_size')->nullable();
            $table->string('crop')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('household_members')->nullable();
            $table->json('proxy')->nullable();
            $table->char('photo_file_uuid', 36)->nullable();
            $table->unsignedSmallInteger('card_issue')->default(0); // 0 = no card yet; reissue increments
            $table->date('trained_restricted_products')->nullable();
            $table->string('status', 32)->default('active');        // active | suspended | deceased | moved | duplicate
            $table->string('verification_status', 32)->default('unverified'); // unverified | verified | rejected
            $table->string('verification_method', 32)->nullable();  // field_visit | community_validation | first_handover
            $table->timestamp('verified_at')->nullable();
            $table->char('verified_by_uuid', 36)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->json('source')->nullable();                     // excel file/sheet/row, paper page, field registration
            $table->char('source_file_uuid', 36)->nullable();
            $table->char('import_batch_uuid', 36)->nullable()->index();
            $table->json('meta')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['programme_uuid', 'status']);
            $table->index(['programme_uuid', 'admin_2']);
            $table->index(['programme_uuid', 'admin_3']);
            $table->index('phone');
            $table->index('id_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_beneficiaries');
    }
};

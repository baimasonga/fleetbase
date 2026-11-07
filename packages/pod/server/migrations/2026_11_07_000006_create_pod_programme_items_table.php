<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The inputs a programme distributes: seed, fertiliser, pesticides, tools,
 * livestock inputs (AGRI-INPUTS.md §5, §9, §10).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_programme_items', function (Blueprint $table) {
            $table->increments('id');
            $table->char('uuid', 36)->unique();
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36);
            $table->string('sku', 64);
            $table->string('name');
            $table->string('category', 32);
            $table->string('unit', 16);
            $table->decimal('pack_size', 10, 3)->nullable();
            $table->string('pack_unit', 16)->nullable();
            $table->boolean('restricted')->default(false);
            $table->boolean('cold_chain')->default(false);
            $table->decimal('max_per_beneficiary', 10, 3)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['programme_uuid', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_programme_items');
    }
};

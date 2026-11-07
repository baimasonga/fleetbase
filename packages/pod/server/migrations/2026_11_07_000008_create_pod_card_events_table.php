<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every programme card action, for audit (AGRI-INPUTS.md §7.2,
 * REPORTING-AUDIT.md §8): issued, reissued (which revokes the previous card),
 * printed, revoked. Rows are only ever added.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pod_card_events', function (Blueprint $table) {
            $table->increments('id');
            $table->char('company_uuid', 36)->index();
            $table->char('programme_uuid', 36);
            $table->char('beneficiary_uuid', 36)->index();
            $table->unsignedSmallInteger('issue_number');
            $table->string('action', 32);  // issued | reissued | printed | revoked
            $table->string('reason', 64)->nullable();
            $table->string('print_batch', 64)->nullable();
            $table->char('user_uuid', 36)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_card_events');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Fleetbase user account of the transporter (a hired trucker or staff
 * driver), so a transporter sees and updates only their own trips.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pod_transport_legs', function (Blueprint $table) {
            $table->uuid('transporter_user_uuid')->nullable()->after('transporter_phone')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pod_transport_legs', function (Blueprint $table) {
            $table->dropIndex(['transporter_user_uuid']);
            $table->dropColumn('transporter_user_uuid');
        });
    }
};

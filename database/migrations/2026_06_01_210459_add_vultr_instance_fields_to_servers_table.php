<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('servers', 'vultr_instance_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('vultr_instance_id')->nullable()->after('hetzner_server_status');
            });
        }

        if (! Schema::hasColumn('servers', 'vultr_instance_status')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->string('vultr_instance_status')->nullable()->after('vultr_instance_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'vultr_instance_status')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('vultr_instance_status');
            });
        }

        if (Schema::hasColumn('servers', 'vultr_instance_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('vultr_instance_id');
            });
        }
    }
};

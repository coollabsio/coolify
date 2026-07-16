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
        Schema::table('servers', function (Blueprint $table) {
            if (! Schema::hasColumn('servers', 'openstack_server_id')) {
                $table->string('openstack_server_id')->nullable()->after('hetzner_server_status');
            }
            if (! Schema::hasColumn('servers', 'openstack_server_status')) {
                $table->string('openstack_server_status')->nullable()->after('openstack_server_id');
            }
            if (! Schema::hasColumn('servers', 'openstack_floating_ip_id')) {
                $table->string('openstack_floating_ip_id')->nullable()->after('openstack_server_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            foreach (['openstack_server_id', 'openstack_server_status', 'openstack_floating_ip_id'] as $column) {
                if (Schema::hasColumn('servers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

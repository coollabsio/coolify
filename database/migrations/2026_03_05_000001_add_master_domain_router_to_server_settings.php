<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_settings', 'is_master_domain_router_enabled')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->boolean('is_master_domain_router_enabled')->default(false)->after('is_cloudflare_tunnel');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('server_settings', 'is_master_domain_router_enabled')) {
            Schema::table('server_settings', function (Blueprint $table) {
                $table->dropColumn('is_master_domain_router_enabled');
            });
        }
    }
};

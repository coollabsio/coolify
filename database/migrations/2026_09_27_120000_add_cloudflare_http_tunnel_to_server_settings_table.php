<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_cloudflare_http_tunnel')->default(false);
            $table->string('cloudflare_http_tunnel_cname')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_cloudflare_http_tunnel',
                'cloudflare_http_tunnel_cname',
            ]);
        });
    }
};

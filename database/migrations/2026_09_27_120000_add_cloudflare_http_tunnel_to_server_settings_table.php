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
            $table->string('cloudflare_http_tunnel_id')->nullable();
            $table->string('cloudflare_http_tunnel_cname')->nullable();
            $table->string('cloudflare_http_tunnel_account_id')->nullable();
            $table->string('cloudflare_http_tunnel_zone_id')->nullable();
            $table->string('cloudflare_http_tunnel_hostname')->nullable();
            $table->text('cloudflare_http_tunnel_token')->nullable();
            $table->foreignId('cloudflare_http_tunnel_integration_token_id')
                ->nullable()
                ->constrained('integration_tokens')
                ->nullOnDelete();
            $table->string('cloudflare_dashboard_hostname')->nullable();
            $table->timestamp('cloudflare_http_tunnel_last_seen_at')->nullable();
            $table->boolean('cloudflare_http_tunnel_use_existing_connector')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloudflare_http_tunnel_integration_token_id');
            $table->dropColumn([
                'is_cloudflare_http_tunnel',
                'cloudflare_http_tunnel_id',
                'cloudflare_http_tunnel_cname',
                'cloudflare_http_tunnel_account_id',
                'cloudflare_http_tunnel_zone_id',
                'cloudflare_http_tunnel_hostname',
                'cloudflare_http_tunnel_token',
                'cloudflare_dashboard_hostname',
                'cloudflare_http_tunnel_last_seen_at',
                'cloudflare_http_tunnel_use_existing_connector',
            ]);
        });
    }
};

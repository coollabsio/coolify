<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('cloudflare_dns_enabled')->default(false)->after('is_cloudflare_tunnel');
            $table->foreignId('cloudflare_dns_token_id')->nullable()->after('cloudflare_dns_enabled')->constrained('cloud_provider_tokens')->nullOnDelete();
            $table->boolean('cloudflare_dns_proxied')->default(false)->after('cloudflare_dns_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropForeign(['cloudflare_dns_token_id']);
            $table->dropColumn([
                'cloudflare_dns_enabled',
                'cloudflare_dns_token_id',
                'cloudflare_dns_proxied',
            ]);
        });
    }
};

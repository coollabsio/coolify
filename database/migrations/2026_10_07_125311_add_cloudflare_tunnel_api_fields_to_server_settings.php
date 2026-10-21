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
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('cloudflare_tunnel_id')->nullable();
            $table->string('cloudflare_tunnel_account_id')->nullable();
            $table->foreignId('cloudflare_tunnel_integration_token_id')->nullable()->constrained('integration_tokens')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloudflare_tunnel_integration_token_id');
            $table->dropColumn(['cloudflare_tunnel_id', 'cloudflare_tunnel_account_id']);
        });
    }
};

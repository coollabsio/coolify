<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Connect3 fork: instance-wide staging apex and Cloudflare DNS-01 token (PRD section 7.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->string('c3_staging_apex')->nullable();
            $table->text('c3_cloudflare_dns_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['c3_staging_apex', 'c3_cloudflare_dns_token']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->json('webhook_allowed_internal_hosts')->nullable();
            $table->boolean('webhook_allow_localhost')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instance_settings', function (Blueprint $table) {
            $table->dropColumn(['webhook_allowed_internal_hosts', 'webhook_allow_localhost']);
        });
    }
};

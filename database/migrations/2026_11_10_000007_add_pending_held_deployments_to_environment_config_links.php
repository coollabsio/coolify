<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_config_links', function (Blueprint $table) {
            $table->json('pending_held_deployments')->nullable();
            $table->timestamp('pending_pushed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environment_config_links', function (Blueprint $table) {
            $table->dropColumn(['pending_held_deployments', 'pending_pushed_at']);
        });
    }
};

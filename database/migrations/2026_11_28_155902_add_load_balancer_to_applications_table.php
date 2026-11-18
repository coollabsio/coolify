<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('load_balancer_strategy')->default('round_robin');
            $table->boolean('is_sticky_session_enabled')->default(false);
            $table->string('sticky_session_cookie_name')->default('lb');
            $table->unsignedInteger('sticky_session_max_age')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['load_balancer_strategy', 'is_sticky_session_enabled', 'sticky_session_cookie_name', 'sticky_session_max_age']);
        });
    }
};

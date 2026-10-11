<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->integer('max_restart_count')->default(10);
            $table->boolean('restart_limit_reached')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('standalone_keydbs', function (Blueprint $table) {
            $table->dropColumn(['max_restart_count', 'restart_limit_reached']);
        });
    }
};

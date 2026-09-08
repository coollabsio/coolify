<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->boolean('restart_limit_reached')->default(false)->after('max_restart_count');
        });

        DB::table('applications')
            ->where('status', 'like', 'exited%')
            ->where('restart_count', '>', 0)
            ->where('max_restart_count', '>', 0)
            ->whereColumn('restart_count', '>=', 'max_restart_count')
            ->where('last_restart_type', 'crash')
            ->update(['restart_limit_reached' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('restart_limit_reached');
        });
    }
};

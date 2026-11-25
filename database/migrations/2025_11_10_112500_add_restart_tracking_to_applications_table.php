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
        if (! Schema::hasColumn('applications', 'restart_count')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->integer('restart_count')->default(0)->after('status');
            });
        }

        if (! Schema::hasColumn('applications', 'last_restart_at')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->timestamp('last_restart_at')->nullable()->after('restart_count');
            });
        }

        if (! Schema::hasColumn('applications', 'last_restart_type')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->string('last_restart_type', 10)->nullable()->after('last_restart_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = ['restart_count', 'last_restart_at', 'last_restart_type'];
        foreach ($columns as $column) {
            if (Schema::hasColumn('applications', $column)) {
                Schema::table('applications', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};

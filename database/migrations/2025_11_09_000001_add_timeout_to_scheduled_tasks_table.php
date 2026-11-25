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
        if (! Schema::hasColumn('scheduled_tasks', 'timeout')) {
            Schema::table('scheduled_tasks', function (Blueprint $table) {
                $table->integer('timeout')->default(300)->after('frequency');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('scheduled_tasks', 'timeout')) {
            Schema::table('scheduled_tasks', function (Blueprint $table) {
                $table->dropColumn('timeout');
            });
        }
    }
};

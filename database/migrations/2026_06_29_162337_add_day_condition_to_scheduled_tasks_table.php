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
        if (! Schema::hasColumn('scheduled_tasks', 'day_condition')) {
            Schema::table('scheduled_tasks', function (Blueprint $table) {
                $table->string('day_condition')->nullable()->after('frequency');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('scheduled_tasks', 'day_condition')) {
            Schema::table('scheduled_tasks', function (Blueprint $table) {
                $table->dropColumn('day_condition');
            });
        }
    }
};

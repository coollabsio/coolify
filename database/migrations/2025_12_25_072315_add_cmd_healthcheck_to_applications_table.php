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
        if (! Schema::hasColumn('applications', 'health_check_type')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->text('health_check_type')->default('http')->after('health_check_enabled');
            });
        }

        if (! Schema::hasColumn('applications', 'health_check_command')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->text('health_check_command')->nullable()->after('health_check_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('applications', 'health_check_type')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('health_check_type');
            });
        }

        if (Schema::hasColumn('applications', 'health_check_command')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('health_check_command');
            });
        }
    }
};

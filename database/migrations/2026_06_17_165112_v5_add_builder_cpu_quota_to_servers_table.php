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
        if (Schema::hasColumn('v5_servers', 'builder_cpu_quota')) {
            return;
        }

        Schema::table('v5_servers', function (Blueprint $table) {
            $table->string('builder_cpu_quota')->default('200%')->after('builder_capacity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('v5_servers', 'builder_cpu_quota')) {
            return;
        }

        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn('builder_cpu_quota');
        });
    }
};

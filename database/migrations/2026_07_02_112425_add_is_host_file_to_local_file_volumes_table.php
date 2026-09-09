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
        if (Schema::hasColumn('local_file_volumes', 'is_host_file')) {
            return;
        }

        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->boolean('is_host_file')->default(false)->after('is_directory');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('local_file_volumes', 'is_host_file')) {
            return;
        }

        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropColumn('is_host_file');
        });
    }
};

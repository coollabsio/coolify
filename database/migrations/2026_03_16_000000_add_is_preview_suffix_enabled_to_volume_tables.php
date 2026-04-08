<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->boolean('is_preview_suffix_enabled')->default(true)->after('is_based_on_git');
        });

        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->boolean('is_preview_suffix_enabled')->default(true)->after('host_path');
        });
    }

    public function down(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropColumn('is_preview_suffix_enabled');
        });

        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->dropColumn('is_preview_suffix_enabled');
        });
    }
};

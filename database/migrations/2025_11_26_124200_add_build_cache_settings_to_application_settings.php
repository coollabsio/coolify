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
        if (! Schema::hasColumn('application_settings', 'inject_build_args_to_dockerfile')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->boolean('inject_build_args_to_dockerfile')->default(true)->after('use_build_secrets');
            });
        }

        if (! Schema::hasColumn('application_settings', 'include_source_commit_in_build')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->boolean('include_source_commit_in_build')->default(false)->after('inject_build_args_to_dockerfile');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('application_settings', 'inject_build_args_to_dockerfile')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('inject_build_args_to_dockerfile');
            });
        }

        if (Schema::hasColumn('application_settings', 'include_source_commit_in_build')) {
            Schema::table('application_settings', function (Blueprint $table) {
                $table->dropColumn('include_source_commit_in_build');
            });
        }
    }
};

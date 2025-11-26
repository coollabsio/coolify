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
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('inject_build_args_to_dockerfile')->default(true)->after('use_build_secrets');
            $table->boolean('include_source_commit_in_build')->default(false)->after('inject_build_args_to_dockerfile');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('inject_build_args_to_dockerfile');
            $table->dropColumn('include_source_commit_in_build');
        });
    }
};

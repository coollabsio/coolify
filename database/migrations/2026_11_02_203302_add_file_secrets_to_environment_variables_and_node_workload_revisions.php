<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cluster application variable can be a file in /run/secrets instead of an
     * environment variable (decision 0008). The revision keeps those values apart.
     */
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->boolean('is_file_secret')->default(false);
        });
        Schema::table('node_workload_revisions', function (Blueprint $table) {
            $table->text('secrets')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('is_file_secret');
        });
        Schema::table('node_workload_revisions', function (Blueprint $table) {
            $table->dropColumn('secrets');
        });
    }
};

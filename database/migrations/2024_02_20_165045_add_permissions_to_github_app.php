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
        Schema::table('github_apps', function (Blueprint $table) {
            $table->string('contents')->nullable();
            $table->string('metadata')->nullable();
            $table->string('pull_requests')->nullable();
            $table->string('administration')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropColumn('contents');
            $table->dropColumn('metadata');
            $table->dropColumn('pull_requests');
            $table->dropColumn('administration');
        });
    }
};

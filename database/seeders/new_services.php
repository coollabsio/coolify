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
        Schema::table('services', function (Blueprint $table) {
            $table->string('git_repository')->nullable();
            $table->string('git_branch')->nullable();
            $table->nullableMorphs('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('git_repository');
            $table->dropColumn('git_branch');
            $table->dropMorphs('source');
        });
    }
};

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
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->foreignId('infisical_binding_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();
            $table->index('infisical_binding_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shared_environment_variables', function (Blueprint $table) {
            $table->dropForeign(['infisical_binding_id']);
            $table->dropColumn('infisical_binding_id');
        });
    }
};

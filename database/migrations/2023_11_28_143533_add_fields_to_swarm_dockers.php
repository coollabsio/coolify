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
        Schema::table('swarm_dockers', function (Blueprint $table) {
            $table->string('network');

            $table->unique(['server_id', 'network']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('swarm_dockers', function (Blueprint $table) {
            $table->dropColumn('network');
        });
    }
};

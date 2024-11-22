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
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->boolean('dump_all')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn('dump_all');
        });
    }
};

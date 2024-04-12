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
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->string('chown')->nullable();
            $table->string('chmod')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropColumn('chown');
            $table->dropColumn('chmod');
        });
    }
};

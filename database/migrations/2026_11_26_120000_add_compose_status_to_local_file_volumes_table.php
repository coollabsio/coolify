<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing rows stay null: only a later successful Compose parse claims a mount as active.
     */
    public function up(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->string('compose_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropColumn('compose_status');
        });
    }
};

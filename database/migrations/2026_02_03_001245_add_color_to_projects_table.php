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
        if (! Schema::hasColumn('projects', 'color')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->string('color', 7)->nullable()->after('description');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('projects', 'color')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->dropColumn('color');
            });
        }
    }
};

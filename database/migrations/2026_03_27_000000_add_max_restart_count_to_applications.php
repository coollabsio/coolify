<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $blueprint) {
            $blueprint->integer('max_restart_count')->default(10)->after('restart_count');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $blueprint) {
            $blueprint->dropColumn('max_restart_count');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_volume_backups', function (Blueprint $table) {
            $table->unsignedInteger('timeout')->default(36000)->change();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_volume_backups', function (Blueprint $table) {
            $table->unsignedInteger('timeout')->default(3600)->change();
        });
    }
};

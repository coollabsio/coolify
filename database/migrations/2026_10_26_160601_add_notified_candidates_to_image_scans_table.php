<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_scans', function (Blueprint $table) {
            $table->jsonb('notified_candidates')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('image_scans', function (Blueprint $table) {
            $table->dropColumn('notified_candidates');
        });
    }
};

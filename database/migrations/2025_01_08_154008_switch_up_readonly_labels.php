<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('application_settings')
            ->update([
                'is_container_label_readonly_enabled' => DB::raw('NOT is_container_label_readonly_enabled'),
            ]);

        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_container_label_readonly_enabled')->default(true)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('application_settings')
            ->update([
                'is_container_label_readonly_enabled' => DB::raw('NOT is_container_label_readonly_enabled'),
            ]);

        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_container_label_readonly_enabled')->default(false)->change();
        });
    }
};

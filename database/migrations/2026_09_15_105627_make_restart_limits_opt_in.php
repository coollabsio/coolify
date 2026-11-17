<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (['applications', 'application_previews', 'service_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->integer('max_restart_count')->default(0)->change();
            });

            DB::table($tableName)
                ->select('id')
                ->where('max_restart_count', 10)
                ->chunkById(5000, function ($resources) use ($tableName): void {
                    DB::table($tableName)
                        ->whereIn('id', $resources->pluck('id'))
                        ->update([
                            'max_restart_count' => 0,
                            'restart_limit_reached' => false,
                        ]);
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['applications', 'application_previews', 'service_applications'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->integer('max_restart_count')->default(10)->change();
            });
        }
    }
};

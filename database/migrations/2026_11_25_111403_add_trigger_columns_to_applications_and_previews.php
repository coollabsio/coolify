<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['applications', 'application_previews'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('trigger_base_commit', 64)->nullable();
                $table->jsonb('trigger_set')->nullable();
                $table->jsonb('trigger_scan')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['applications', 'application_previews'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['trigger_base_commit', 'trigger_set', 'trigger_scan']);
            });
        }
    }
};

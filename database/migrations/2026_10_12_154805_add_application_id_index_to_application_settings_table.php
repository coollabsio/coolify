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
        if (Schema::hasIndex('application_settings', ['application_id'])) {
            return;
        }

        Schema::table('application_settings', function (Blueprint $table) {
            $table->index('application_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('application_settings', ['application_id'])) {
            return;
        }

        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
        });
    }
};

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
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('smtp_notifications_deployments')->default(true)->change();
            $table->boolean('smtp_notifications_status_changes')->default(true)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->boolean('smtp_notifications_deployments')->default(false)->change();
            $table->boolean('smtp_notifications_status_changes')->default(false)->change();
        });
    }
};

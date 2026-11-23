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
        Schema::table('webhook_notification_settings', function (Blueprint $table) {
            $table->text('webhook_authorization_header')->nullable()->after('webhook_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webhook_notification_settings', function (Blueprint $table) {
            $table->dropColumn('webhook_authorization_header');
        });
    }
};

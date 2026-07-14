<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('slack_notification_settings', function (Blueprint $table) {
            $table->boolean('include_project_name_in_title')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('slack_notification_settings', function (Blueprint $table) {
            $table->dropColumn('include_project_name_in_title');
        });
    }
};

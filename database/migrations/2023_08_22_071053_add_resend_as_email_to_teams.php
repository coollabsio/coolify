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
            $table->boolean('resend_enabled')->default(false);
            $table->text('resend_api_key')->nullable();
            $table->boolean('use_instance_email_settings')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('resend_enabled');
            $table->dropColumn('resend_api_key');
            $table->dropColumn('use_instance_email_settings');
        });
    }
};

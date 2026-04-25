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
        Schema::table('applications', function (Blueprint $table) {
            $table->text('http_basic_auth_password')->nullable()->default(null)->change();
            $table->text('manual_webhook_secret_github')->nullable()->change();
            $table->text('manual_webhook_secret_gitlab')->nullable()->change();
            $table->text('manual_webhook_secret_bitbucket')->nullable()->change();
            $table->text('manual_webhook_secret_gitea')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('http_basic_auth_password')->nullable()->default(null)->change();
            $table->string('manual_webhook_secret_github')->nullable()->change();
            $table->string('manual_webhook_secret_gitlab')->nullable()->change();
            $table->string('manual_webhook_secret_bitbucket')->nullable()->change();
            $table->string('manual_webhook_secret_gitea')->nullable()->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('patch_check_frequency')->nullable();
            $table->json('patch_check_result')->nullable();
            $table->text('patch_check_error')->nullable();
            $table->timestamp('patch_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['patch_check_frequency', 'patch_check_result', 'patch_check_error', 'patch_checked_at']);
        });
    }
};

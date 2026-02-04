<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds global admin flag and status column to users table
     * for the new permission system.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_global_admin')->default(false)->after('email');
            $table->string('status', 20)->default('active')->after('is_global_admin');
            $table->index('status');
            $table->index('is_global_admin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['is_global_admin']);
            $table->dropColumn(['is_global_admin', 'status']);
        });
    }
};

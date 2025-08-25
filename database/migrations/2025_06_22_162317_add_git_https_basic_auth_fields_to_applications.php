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
            // Add git authentication type field - defaults to 'deploy_key' for backward compatibility
            $table->string('git_auth_type')->default('deploy_key')->after('git_type');
            
            // Add HTTPS basic auth fields
            $table->string('git_basic_auth_username')->nullable()->after('git_auth_type');
            $table->string('git_basic_auth_password')->nullable()->after('git_basic_auth_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('git_auth_type');
            $table->dropColumn('git_basic_auth_username');
            $table->dropColumn('git_basic_auth_password');
        });
    }
};
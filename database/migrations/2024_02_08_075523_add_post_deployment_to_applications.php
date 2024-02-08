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
            $table->string('post_deployment_command')->nullable();
            $table->string('post_deployment_command_container')->nullable();
            $table->string('pre_deployment_command')->nullable();
            $table->string('pre_deployment_command_container')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('post_deployment_command');
            $table->dropColumn('post_deployment_command_container');
            $table->dropColumn('pre_deployment_command');
            $table->dropColumn('pre_deployment_command_container');
        });
    }
};

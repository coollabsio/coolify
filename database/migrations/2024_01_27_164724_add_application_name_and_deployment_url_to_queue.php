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
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->string('application_name')->nullable();
            $table->string('server_name')->nullable();
            $table->string('deployment_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn('application_name');
            $table->dropColumn('server_name');
            $table->dropColumn('deployment_url');
        });
    }
};

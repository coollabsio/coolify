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
            $table->string('horizon_job_id')->nullable();
            $table->string('horizon_job_worker')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn('horizon_job_id');
            $table->dropColumn('horizon_job_worker');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable();
        });
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable();
        });

        Schema::table('scheduled_task_executions', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable();
        });

    }

    public function down()
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });

        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });

        Schema::table('scheduled_task_executions', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });
    }
};

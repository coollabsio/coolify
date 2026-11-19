<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Links the deployments on additional servers to the main deployment, so Coolify can send one
     * notification after all servers finish.
     */
    public function up(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->string('parent_deployment_uuid')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropIndex(['parent_deployment_uuid']);
            $table->dropColumn('parent_deployment_uuid');
        });
    }
};

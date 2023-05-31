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
        Schema::create('application_deployment_queues', function (Blueprint $table) {
            $table->id();
            $table->string('application_id');
            $table->string('deployment_uuid')->unique();
            $table->integer('pull_request_id')->default(0);
            $table->boolean('force_rebuild')->default(false);
            $table->string('commit')->default('HEAD');
            $table->string('status')->default('queued');
            $table->boolean('is_webhook')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('application_deployment_queues');
    }
};

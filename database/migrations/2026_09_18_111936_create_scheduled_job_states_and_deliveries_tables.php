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
        Schema::create('scheduled_job_states', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('schedule_key')->unique();
            $table->timestampTz('last_scheduled_for')->nullable();
            $table->timestamps();
        });

        Schema::create('scheduled_job_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('schedule_key');
            $table->timestampTz('scheduled_for');
            $table->string('job_type');
            $table->unsignedBigInteger('resource_id');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->string('claim_token')->nullable();
            $table->timestampTz('enqueued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_key', 'scheduled_for']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_job_deliveries');
        Schema::dropIfExists('scheduled_job_states');
    }
};

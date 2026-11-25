<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->jsonb('trigger_reason')->nullable();
            $table->unsignedInteger('skipped_count')->nullable();
            $table->string('first_skipped_commit', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn(['trigger_reason', 'skipped_count', 'first_skipped_commit']);
        });
    }
};

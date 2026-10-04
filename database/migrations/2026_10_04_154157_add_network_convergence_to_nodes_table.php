<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('network_status')->nullable();
            $table->text('network_error')->nullable();
            $table->unsignedInteger('network_attempts')->default(0);
            $table->timestamp('network_next_attempt_at')->nullable();
            $table->json('network_pending_leave')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['network_status', 'network_error', 'network_attempts', 'network_next_attempt_at', 'network_pending_leave']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_notification_report_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('item_type');
            $table->string('item_key');
            $table->string('fingerprint', 64);
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'item_type', 'item_key'], 'update_report_states_team_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_notification_report_states');
    }
};

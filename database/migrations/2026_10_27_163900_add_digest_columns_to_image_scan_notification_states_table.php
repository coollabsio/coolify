<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notified_ids` keeps the findings with an exploit signal that an immediate alert named; `digest_ids` the findings
 * with an action that a digest (or an alert) named; `check_reported` whether a digest said the resource needs a check;
 * `held_digests` how many digests held new problems back while the resource needed a check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_scan_notification_states', function (Blueprint $table) {
            $table->jsonb('digest_ids')->nullable();
            $table->boolean('check_reported')->default(false);
            $table->unsignedSmallInteger('held_digests')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('image_scan_notification_states', function (Blueprint $table) {
            $table->dropColumn(['digest_ids', 'check_reported', 'held_digests']);
        });
    }
};

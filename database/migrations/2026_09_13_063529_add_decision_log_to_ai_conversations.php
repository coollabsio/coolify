<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            // Records each resolved tool-approval decision, keyed by tool-call id:
            // ['decision' => 'approved'|'cancelled', 'reason' => '<action description>'].
            $table->json('decision_log')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table) {
            $table->dropColumn('decision_log');
        });
    }
};

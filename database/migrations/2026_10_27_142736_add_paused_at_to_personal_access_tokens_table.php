<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'paused_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('paused_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('paused_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->timestamp('agent_token_expires_at')->nullable()->after('agent_token_jti');
        });
    }

    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn('agent_token_expires_at');
        });
    }
};

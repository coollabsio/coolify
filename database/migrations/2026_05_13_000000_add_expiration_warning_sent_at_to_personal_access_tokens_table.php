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
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('api_token_expiration_warning_sent_at')->nullable()->after('expires_at');
            $table->index(['expires_at', 'api_token_expiration_warning_sent_at'], 'personal_access_tokens_expiration_warning_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('personal_access_tokens_expiration_warning_index');
            $table->dropColumn('api_token_expiration_warning_sent_at');
        });
    }
};

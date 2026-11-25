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
        if (! Schema::hasColumn('servers', 'cloud_provider_token_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->foreignId('cloud_provider_token_id')->nullable()->after('private_key_id')->constrained()->onDelete('set null');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'cloud_provider_token_id')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropForeign(['cloud_provider_token_id']);
                $table->dropColumn('cloud_provider_token_id');
            });
        }
    }
};

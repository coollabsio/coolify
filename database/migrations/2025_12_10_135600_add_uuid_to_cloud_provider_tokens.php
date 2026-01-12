<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Visus\Cuid2\Cuid2;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('cloud_provider_tokens', 'uuid')) {
            Schema::table('cloud_provider_tokens', function (Blueprint $table) {
                $table->string('uuid')->nullable()->unique()->after('id');
            });

            // Generate UUIDs for existing records using chunked processing
            DB::table('cloud_provider_tokens')
                ->whereNull('uuid')
                ->chunkById(500, function ($tokens) {
                    foreach ($tokens as $token) {
                        DB::table('cloud_provider_tokens')
                            ->where('id', $token->id)
                            ->update(['uuid' => (string) new Cuid2]);
                    }
                });

            // Make uuid non-nullable after filling in values
            Schema::table('cloud_provider_tokens', function (Blueprint $table) {
                $table->string('uuid')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('cloud_provider_tokens', 'uuid')) {
            Schema::table('cloud_provider_tokens', function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }
    }
};

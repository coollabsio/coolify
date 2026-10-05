<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Until v4.3.23, the Redis start command and connection URLs used the stored REDIS_PASSWORD value
     * as it is (a shared variable reference stayed text) and the command did not quote it. Existing
     * databases keep that server password; databases created later resolve and quote it.
     */
    public function up(): void
    {
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->boolean('legacy_password_quoting')->default(false);
        });

        DB::table('standalone_redis')->update(['legacy_password_quoting' => true]);
    }

    public function down(): void
    {
        Schema::table('standalone_redis', function (Blueprint $table) {
            $table->dropColumn('legacy_password_quoting');
        });
    }
};

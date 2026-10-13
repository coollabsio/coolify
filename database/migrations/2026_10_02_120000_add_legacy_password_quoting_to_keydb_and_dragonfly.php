<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['standalone_keydbs', 'standalone_dragonflies'];

    /**
     * Until v4.3.23, the KeyDB and Dragonfly start commands contained the password unquoted, so Docker
     * Compose removed backslashes and quotes from it. Existing databases keep that server password;
     * databases created later use the stored password exactly.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->boolean('legacy_password_quoting')->default(false);
            });

            DB::table($tableName)->update(['legacy_password_quoting' => true]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('legacy_password_quoting');
            });
        }
    }
};

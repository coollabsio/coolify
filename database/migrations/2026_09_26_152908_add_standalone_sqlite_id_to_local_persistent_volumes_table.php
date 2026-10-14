<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which SQLite database a volume was connected from, so the mount
     * can be traced back to its database without matching volume names.
     */
    public function up(): void
    {
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->foreignId('standalone_sqlite_id')->nullable()->after('host_path')->constrained('standalone_sqlites')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('local_persistent_volumes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('standalone_sqlite_id');
        });
    }
};

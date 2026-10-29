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
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->boolean('encryption_enabled')->default(false)->after('disable_local_backup');
            $table->foreignId('age_key_id')->nullable()->after('encryption_enabled')->constrained('age_keys')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('age_key_id');
            $table->dropColumn('encryption_enabled');
        });
    }
};

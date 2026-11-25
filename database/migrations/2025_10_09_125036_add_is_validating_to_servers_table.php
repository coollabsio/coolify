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
        if (! Schema::hasColumn('servers', 'is_validating')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->boolean('is_validating')->default(false)->after('hetzner_server_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('servers', 'is_validating')) {
            Schema::table('servers', function (Blueprint $table) {
                $table->dropColumn('is_validating');
            });
        }
    }
};

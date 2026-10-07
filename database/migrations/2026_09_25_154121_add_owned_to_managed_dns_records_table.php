<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing rows stay not owned: before this change Coolify tracked adopted
     * (hand-made) records exactly like the records it created and wrote no
     * ownership comment, so neither can be told apart safely.
     */
    public function up(): void
    {
        Schema::table('managed_dns_records', function (Blueprint $table) {
            $table->boolean('owned')->default(false)->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('managed_dns_records', function (Blueprint $table) {
            $table->dropColumn('owned');
        });
    }
};

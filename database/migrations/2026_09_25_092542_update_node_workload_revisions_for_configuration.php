<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_workload_revisions', function (Blueprint $table) {
            $table->dropUnique(['node_workload_id', 'configuration_hash']);
            $table->index(['node_workload_id', 'configuration_hash']);
            $table->text('environment')->nullable()->after('configuration');
        });
    }

    public function down(): void
    {
        Schema::table('node_workload_revisions', function (Blueprint $table) {
            $table->dropColumn('environment');
            $table->dropIndex(['node_workload_id', 'configuration_hash']);
            $table->unique(['node_workload_id', 'configuration_hash']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('node_operations', function (Blueprint $table) {
            $table->index(['node_workload_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('node_operations', function (Blueprint $table) {
            $table->dropIndex(['node_workload_id', 'status']);
        });
    }
};

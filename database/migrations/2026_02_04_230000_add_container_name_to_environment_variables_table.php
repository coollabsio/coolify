<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add container_name field to scope environment variables to specific containers
     * within Docker Compose services. When null, the variable applies to all containers.
     * This addresses the security issue where all env vars were shared across all containers.
     *
     * @see https://github.com/coollabsio/coolify/issues/7655
     */
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->string('container_name')->nullable()->after('is_shared');
            $table->index('container_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropIndex(['container_name']);
            $table->dropColumn('container_name');
        });
    }
};

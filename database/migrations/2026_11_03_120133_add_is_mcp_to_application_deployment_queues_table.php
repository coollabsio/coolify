<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('application_deployment_queues', 'is_mcp')) {
            return;
        }

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->boolean('is_mcp')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('application_deployment_queues', 'is_mcp')) {
            return;
        }

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->dropColumn('is_mcp');
        });
    }
};

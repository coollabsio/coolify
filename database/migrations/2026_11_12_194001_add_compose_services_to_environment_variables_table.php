<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('environment_variables', 'compose_services')) {
            return;
        }

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->json('compose_services')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('environment_variables', 'compose_services')) {
            return;
        }

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('compose_services');
        });
    }
};

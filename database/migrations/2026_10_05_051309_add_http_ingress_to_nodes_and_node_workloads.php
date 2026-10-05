<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('is_ingress')->default(false);
        });
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->json('domains')->nullable();
            $table->unsignedInteger('http_port')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_workloads', function (Blueprint $table) {
            $table->dropColumn(['domains', 'http_port']);
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('is_ingress');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->after('service_id')->constrained()->cascadeOnDelete();
        });

        // Make service_id nullable so Application-linked databases don't need it
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');
        });

        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->unsignedBigInteger('application_id')->nullable()->after('service_id');
            $table->unsignedBigInteger('service_id')->nullable()->change();
            $table->index(['application_id', 'name']);
            $table->index(['service_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropIndex(['application_id', 'name']);
            $table->dropIndex(['service_id', 'name']);
            $table->dropColumn('application_id');
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};


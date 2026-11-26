<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            $table->boolean('is_removed_from_compose')->default(false);
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->boolean('is_removed_from_compose')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('service_applications', function (Blueprint $table) {
            $table->dropColumn('is_removed_from_compose');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropColumn('is_removed_from_compose');
        });
    }
};

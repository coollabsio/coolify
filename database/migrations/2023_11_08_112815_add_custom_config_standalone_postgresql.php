<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->longText('postgres_conf')->nullable();
            $table->string('image')->default('postgres:16-alpine')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn('postgres_conf');
            $table->string('image')->default('postgres:15-alpine')->change();
        });
    }
};

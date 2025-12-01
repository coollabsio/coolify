<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->boolean('pgbackrest_enabled')->default(false)->after('postgres_conf');
            $table->integer('pgbackrest_retention_full')->default(2)->after('pgbackrest_enabled');
            $table->integer('pgbackrest_retention_diff')->default(7)->after('pgbackrest_retention_full');
            $table->string('pgbackrest_log_level')->default('info')->after('pgbackrest_retention_diff');
            $table->string('pgbackrest_compress_type')->default('lz4')->after('pgbackrest_log_level');
            $table->integer('pgbackrest_compress_level')->default(6)->after('pgbackrest_compress_type');
        });
    }

    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn([
                'pgbackrest_enabled',
                'pgbackrest_retention_full',
                'pgbackrest_retention_diff',
                'pgbackrest_log_level',
                'pgbackrest_compress_type',
                'pgbackrest_compress_level',
            ]);
        });
    }
};

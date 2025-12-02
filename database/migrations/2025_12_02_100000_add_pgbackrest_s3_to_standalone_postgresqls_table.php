<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->string('pgbackrest_repo_type')->default('posix')->after('pgbackrest_compress_level');
            $table->string('pgbackrest_s3_bucket')->nullable()->after('pgbackrest_repo_type');
            $table->string('pgbackrest_s3_endpoint')->nullable()->after('pgbackrest_s3_bucket');
            $table->string('pgbackrest_s3_region')->nullable()->after('pgbackrest_s3_endpoint');
            $table->text('pgbackrest_s3_key')->nullable()->after('pgbackrest_s3_region');
            $table->text('pgbackrest_s3_secret')->nullable()->after('pgbackrest_s3_key');
            $table->string('pgbackrest_s3_uri_style')->default('path')->after('pgbackrest_s3_secret');
            $table->boolean('pgbackrest_s3_verify_tls')->default(true)->after('pgbackrest_s3_uri_style');
        });
    }

    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropColumn([
                'pgbackrest_repo_type',
                'pgbackrest_s3_bucket',
                'pgbackrest_s3_endpoint',
                'pgbackrest_s3_region',
                'pgbackrest_s3_key',
                'pgbackrest_s3_secret',
                'pgbackrest_s3_uri_style',
                'pgbackrest_s3_verify_tls',
            ]);
        });
    }
};

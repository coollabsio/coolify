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
            $table->foreignId('pgbackrest_s3_storage_id')->nullable()->after('pgbackrest_repo_type')->constrained('s3_storages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('standalone_postgresqls', function (Blueprint $table) {
            $table->dropForeign(['pgbackrest_s3_storage_id']);
            $table->dropColumn([
                'pgbackrest_repo_type',
                'pgbackrest_s3_storage_id',
            ]);
        });
    }
};

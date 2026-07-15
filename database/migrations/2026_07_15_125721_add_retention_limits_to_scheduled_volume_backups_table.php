<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_volume_backups', function (Blueprint $table) {
            $table->unsignedInteger('retention_days_locally')->default(0);
            $table->decimal('retention_max_storage_locally', 17, 7)->default(0);
            $table->unsignedInteger('retention_days_s3')->default(0);
            $table->decimal('retention_max_storage_s3', 17, 7)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_volume_backups', function (Blueprint $table) {
            $table->dropColumn([
                'retention_days_locally',
                'retention_max_storage_locally',
                'retention_days_s3',
                'retention_max_storage_s3',
            ]);
        });
    }
};

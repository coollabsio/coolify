<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropUnique('local_file_volumes_mount_path_resource_id_resource_type_unique');
            $table->unique(
                ['fs_path', 'mount_path', 'resource_id', 'resource_type'],
                'local_file_volumes_source_mount_resource_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropUnique('local_file_volumes_source_mount_resource_unique');
            $table->unique(['mount_path', 'resource_id', 'resource_type']);
        });
    }
};

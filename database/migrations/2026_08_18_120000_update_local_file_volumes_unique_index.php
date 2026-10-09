<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->string('fs_path_hash', 64)->nullable();
        });

        DB::table('local_file_volumes')
            ->select(['id', 'fs_path'])
            ->chunkById(500, function ($volumes): void {
                foreach ($volumes as $volume) {
                    DB::table('local_file_volumes')
                        ->where('id', $volume->id)
                        ->update(['fs_path_hash' => hash('sha256', $volume->fs_path)]);
                }
            });

        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->string('fs_path_hash', 64)->nullable(false)->change();
            $table->dropUnique('local_file_volumes_mount_path_resource_id_resource_type_unique');
            $table->unique(
                ['fs_path_hash', 'mount_path', 'resource_id', 'resource_type'],
                'local_file_volumes_source_mount_resource_unique'
            );
        });
    }

    public function down(): void
    {
        $hasIncompatibleSiblingMounts = DB::table('local_file_volumes')
            ->select(['mount_path', 'resource_id', 'resource_type'])
            ->groupBy(['mount_path', 'resource_id', 'resource_type'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasIncompatibleSiblingMounts) {
            throw new RuntimeException(
                'Cannot roll back the local file volume unique index while sibling file mounts exist.'
            );
        }

        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->dropUnique('local_file_volumes_source_mount_resource_unique');
            $table->unique(
                ['mount_path', 'resource_id', 'resource_type'],
                'local_file_volumes_mount_path_resource_id_resource_type_unique'
            );
            $table->dropColumn('fs_path_hash');
        });
    }
};

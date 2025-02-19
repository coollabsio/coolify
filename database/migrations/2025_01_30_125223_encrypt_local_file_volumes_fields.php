<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->text('mount_path')->nullable()->change();
        });

        if (DB::table('local_file_volumes')->exists()) {
            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) {
                    foreach ($volumes as $volume) {
                        try {
                            DB::table('local_file_volumes')->where('id', $volume->id)->update([
                                'fs_path' => $volume->fs_path ? Crypt::encryptString($volume->fs_path) : null,
                                'mount_path' => $volume->mount_path ? Crypt::encryptString($volume->mount_path) : null,
                                'content' => $volume->content ? Crypt::encryptString($volume->content) : null,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error encrypting local file volume fields: '.$e->getMessage());
                        }
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('local_file_volumes', function (Blueprint $table) {
            $table->string('fs_path')->change();
            $table->string('mount_path')->nullable()->change();
            $table->longText('content')->nullable()->change();
        });

        if (DB::table('local_file_volumes')->exists()) {
            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) {
                    foreach ($volumes as $volume) {
                        try {
                            DB::table('local_file_volumes')->where('id', $volume->id)->update([
                                'fs_path' => $volume->fs_path ? Crypt::decryptString($volume->fs_path) : null,
                                'mount_path' => $volume->mount_path ? Crypt::decryptString($volume->mount_path) : null,
                                'content' => $volume->content ? Crypt::decryptString($volume->content) : null,
                            ]);
                        } catch (\Exception $e) {
                            Log::error('Error decrypting local file volume fields: '.$e->getMessage());
                        }
                    }
                });
        }
    }
};

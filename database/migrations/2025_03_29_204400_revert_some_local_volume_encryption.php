<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::table('local_file_volumes')->exists()) {
            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) {
                    foreach ($volumes as $volume) {
                        DB::beginTransaction();

                        try {
                            $fs_path = $volume->fs_path;
                            $mount_path = $volume->mount_path;
                            try {
                                if ($fs_path) {
                                    $fs_path = Crypt::decryptString($fs_path);
                                }
                            } catch (\Exception $e) {
                            }

                            try {
                                if ($mount_path) {
                                    $mount_path = Crypt::decryptString($mount_path);
                                }
                            } catch (\Exception $e) {
                            }

                            DB::table('local_file_volumes')->where('id', $volume->id)->update([
                                'fs_path' => $fs_path,
                                'mount_path' => $mount_path,
                            ]);
                            echo "Updated volume {$volume->id}\n";
                        } catch (\Exception $e) {
                            echo "Error encrypting local file volume fields: {$e->getMessage()}\n";
                            Log::error('Error encrypting local file volume fields: '.$e->getMessage());
                        }
                        DB::commit();
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('local_file_volumes')->exists()) {
            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) {
                    foreach ($volumes as $volume) {
                        DB::beginTransaction();
                        try {
                            $fs_path = $volume->fs_path;
                            $mount_path = $volume->mount_path;
                            try {
                                if ($fs_path) {
                                    $fs_path = Crypt::encrypt($fs_path);
                                }
                            } catch (\Exception $e) {
                            }

                            try {
                                if ($mount_path) {
                                    $mount_path = Crypt::encrypt($mount_path);
                                }
                            } catch (\Exception $e) {
                            }

                            DB::table('local_file_volumes')->where('id', $volume->id)->update([
                                'fs_path' => $fs_path,
                                'mount_path' => $mount_path,
                            ]);
                            echo "Updated volume {$volume->id}\n";
                        } catch (\Exception $e) {
                            echo "Error decrypting local file volume fields: {$e->getMessage()}\n";
                            Log::error('Error decrypting local file volume fields: '.$e->getMessage());
                        }
                        DB::commit();
                    }
                });
        }
    }
};

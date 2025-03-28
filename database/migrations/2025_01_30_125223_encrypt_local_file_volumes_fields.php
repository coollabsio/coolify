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
            DB::beginTransaction();
            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) {
                    foreach ($volumes as $volume) {
                        try {
                            $fs_path = $volume->fs_path;
                            $mount_path = $volume->mount_path;
                            $content = $volume->content;
                            // Check if fields are already encrypted by attempting to decrypt
                            try {
                                if ($fs_path) {
                                    Crypt::decryptString($fs_path);
                                }
                            } catch (\Exception $e) {
                                $fs_path = $fs_path ? Crypt::encryptString($fs_path) : null;
                            }

                            try {
                                if ($mount_path) {
                                    Crypt::decryptString($mount_path);
                                }
                            } catch (\Exception $e) {
                                $mount_path = $mount_path ? Crypt::encryptString($mount_path) : null;
                            }

                            try {
                                if ($content) {
                                    Crypt::decryptString($content);
                                }
                            } catch (\Exception $e) {
                                $content = $content ? Crypt::encryptString($content) : null;
                            }

                            DB::table('local_file_volumes')->where('id', $volume->id)->update([
                                'fs_path' => $fs_path,
                                'mount_path' => $mount_path,
                                'content' => $content,
                            ]);
                            echo "Updated volume {$volume->id}\n";
                        } catch (\Exception $e) {
                            echo "Error encrypting local file volume fields: {$e->getMessage()}\n";
                            Log::error('Error encrypting local file volume fields: '.$e->getMessage());
                        }
                    }
                });
            DB::commit();
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
            DB::beginTransaction();
            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) {
                    foreach ($volumes as $volume) {
                        try {
                            $fs_path = $volume->fs_path;
                            $mount_path = $volume->mount_path;
                            $content = $volume->content;
                            // Check if fields are already decrypted by attempting to decrypt
                            try {
                                if ($fs_path) {
                                    Crypt::decryptString($fs_path);
                                }
                            } catch (\Exception $e) {
                                $fs_path = $fs_path ? Crypt::decryptString($fs_path) : null;
                            }

                            try {
                                if ($mount_path) {
                                    Crypt::decryptString($mount_path);
                                }
                            } catch (\Exception $e) {
                                $mount_path = $mount_path ? Crypt::decryptString($mount_path) : null;
                            }

                            try {
                                if ($content) {
                                    Crypt::decryptString($content);
                                }
                            } catch (\Exception $e) {
                                $content = $content ? Crypt::decryptString($content) : null;
                            }

                            DB::table('local_file_volumes')->where('id', $volume->id)->update([
                                'fs_path' => $fs_path,
                                'mount_path' => $mount_path,
                                'content' => $content,
                            ]);
                            echo "Updated volume {$volume->id}\n";
                        } catch (\Exception $e) {
                            echo "Error decrypting local file volume fields: {$e->getMessage()}\n";
                            Log::error('Error decrypting local file volume fields: '.$e->getMessage());
                        }
                    }
                });
            DB::commit();
        }
    }
};

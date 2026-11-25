<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Trims whitespace from S3 storage fields (key, secret, endpoint, bucket, region)
     * to fix "Malformed Access Key Id" errors that can occur when users
     * accidentally paste values with leading/trailing whitespace.
     */
    public function up(): void
    {
        DB::table('s3_storages')
            ->select(['id', 'key', 'secret', 'endpoint', 'bucket', 'region'])
            ->orderBy('id')
            ->chunk(100, function ($storages) {
                foreach ($storages as $storage) {
                    try {
                        DB::transaction(function () use ($storage) {
                            $updates = [];

                            // Trim endpoint (not encrypted)
                            if ($storage->endpoint !== null) {
                                $trimmedEndpoint = trim($storage->endpoint);
                                if ($trimmedEndpoint !== $storage->endpoint) {
                                    $updates['endpoint'] = $trimmedEndpoint;
                                }
                            }

                            // Trim bucket (not encrypted)
                            if ($storage->bucket !== null) {
                                $trimmedBucket = trim($storage->bucket);
                                if ($trimmedBucket !== $storage->bucket) {
                                    $updates['bucket'] = $trimmedBucket;
                                }
                            }

                            // Trim region (not encrypted)
                            if ($storage->region !== null) {
                                $trimmedRegion = trim($storage->region);
                                if ($trimmedRegion !== $storage->region) {
                                    $updates['region'] = $trimmedRegion;
                                }
                            }

                            // Trim key (encrypted) - verify re-encryption works before saving
                            if ($storage->key !== null) {
                                try {
                                    $decryptedKey = Crypt::decryptString($storage->key);
                                    $trimmedKey = trim($decryptedKey);
                                    if ($trimmedKey !== $decryptedKey) {
                                        $encryptedKey = Crypt::encryptString($trimmedKey);
                                        // Verify the new encryption is valid
                                        if (Crypt::decryptString($encryptedKey) === $trimmedKey) {
                                            $updates['key'] = $encryptedKey;
                                        } else {
                                            Log::warning("S3 storage ID {$storage->id}: Re-encryption verification failed for key, skipping");
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning("Could not decrypt S3 storage key for ID {$storage->id}: ".$e->getMessage());
                                }
                            }

                            // Trim secret (encrypted) - verify re-encryption works before saving
                            if ($storage->secret !== null) {
                                try {
                                    $decryptedSecret = Crypt::decryptString($storage->secret);
                                    $trimmedSecret = trim($decryptedSecret);
                                    if ($trimmedSecret !== $decryptedSecret) {
                                        $encryptedSecret = Crypt::encryptString($trimmedSecret);
                                        // Verify the new encryption is valid
                                        if (Crypt::decryptString($encryptedSecret) === $trimmedSecret) {
                                            $updates['secret'] = $encryptedSecret;
                                        } else {
                                            Log::warning("S3 storage ID {$storage->id}: Re-encryption verification failed for secret, skipping");
                                        }
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning("Could not decrypt S3 storage secret for ID {$storage->id}: ".$e->getMessage());
                                }
                            }

                            if (! empty($updates)) {
                                DB::table('s3_storages')->where('id', $storage->id)->update($updates);
                                Log::info("Trimmed whitespace from S3 storage credentials for ID {$storage->id}", [
                                    'fields_updated' => array_keys($updates),
                                ]);
                            }
                        });
                    } catch (\Throwable $e) {
                        Log::error("Failed to process S3 storage ID {$storage->id}: ".$e->getMessage());
                        // Continue with next record instead of failing entire migration
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse trimming operation
    }
};

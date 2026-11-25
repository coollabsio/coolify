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
        echo "Starting local file volumes migration...\n";

        if (DB::table('local_file_volumes')->exists()) {
            echo "Found local_file_volumes table, proceeding with migration...\n";
            // First, get all volumes and decrypt their values
            $decryptedVolumes = collect();
            $totalVolumes = DB::table('local_file_volumes')->count();
            echo "Total volumes to process: {$totalVolumes}\n";

            DB::table('local_file_volumes')
                ->orderBy('id')
                ->chunk(100, function ($volumes) use (&$decryptedVolumes) {
                    echo 'Processing chunk of '.count($volumes)." volumes...\n";
                    foreach ($volumes as $volume) {
                        try {
                            $fs_path = $volume->fs_path;
                            $mount_path = $volume->mount_path;

                            try {
                                if ($fs_path) {
                                    $fs_path = Crypt::decryptString($fs_path);
                                }
                            } catch (\Exception $e) {
                                echo "Warning: Could not decrypt fs_path for volume {$volume->id}\n";
                            }

                            try {
                                if ($mount_path) {
                                    $mount_path = Crypt::decryptString($mount_path);
                                }
                            } catch (\Exception $e) {
                                echo "Warning: Could not decrypt mount_path for volume {$volume->id}\n";
                            }

                            $decryptedVolumes->push([
                                'id' => $volume->id,
                                'fs_path' => $fs_path,
                                'mount_path' => $mount_path,
                                'resource_id' => $volume->resource_id,
                                'resource_type' => $volume->resource_type,
                            ]);

                        } catch (\Exception $e) {
                            echo "Error decrypting volume {$volume->id}: {$e->getMessage()}\n";
                            Log::error("Error decrypting volume {$volume->id}: ".$e->getMessage());
                        }
                    }
                });

            echo 'Finished processing all volumes. Found '.$decryptedVolumes->count()." total volumes.\n";

            // Group by the unique constraint fields and keep only the first occurrence
            $uniqueVolumes = $decryptedVolumes->groupBy(function ($volume) {
                return $volume['mount_path'].'|'.$volume['resource_id'].'|'.$volume['resource_type'];
            })->map(function ($group) {
                return $group->first();
            });

            echo 'After deduplication, found '.$uniqueVolumes->count()." unique volumes.\n";

            // Get IDs to delete (all except the ones we're keeping)
            $idsToKeep = $uniqueVolumes->pluck('id')->toArray();
            $idsToDelete = $decryptedVolumes->pluck('id')->diff($idsToKeep)->toArray();

            // Delete duplicate records
            if (! empty($idsToDelete)) {
                echo "\nFound ".count($idsToDelete)." duplicate volumes to delete.\n";
                // Show details of volumes being deleted
                $volumesToDelete = $decryptedVolumes->whereIn('id', $idsToDelete);
                echo "\nVolumes to be deleted:\n";
                foreach ($volumesToDelete as $volume) {
                    echo "ID: {$volume['id']}, Mount Path: {$volume['mount_path']}, Resource ID: {$volume['resource_id']}, Resource Type: {$volume['resource_type']}\n";
                    echo "FS Path: {$volume['fs_path']}\n";
                    echo "-------------------\n";
                }

                DB::table('local_file_volumes')->whereIn('id', $idsToDelete)->delete();
                echo 'Deleted '.count($idsToDelete)." duplicate volume(s)\n";
            }

            echo "\nUpdating remaining volumes with decrypted values...\n";
            $updateCount = 0;
            // Update the remaining records with decrypted values
            foreach ($uniqueVolumes as $volume) {
                try {
                    DB::table('local_file_volumes')->where('id', $volume['id'])->update([
                        'fs_path' => $volume['fs_path'],
                        'mount_path' => $volume['mount_path'],
                    ]);
                    $updateCount++;
                } catch (\Exception $e) {
                    echo "Error updating volume {$volume['id']}: {$e->getMessage()}\n";
                    Log::error("Error updating volume {$volume['id']}: ".$e->getMessage());
                }
            }
            echo "Successfully updated {$updateCount} volumes.\n";
        } else {
            echo "No local_file_volumes table found, skipping migration.\n";
        }

        echo "Migration completed successfully.\n";
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

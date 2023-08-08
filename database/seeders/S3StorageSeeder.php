<?php

namespace Database\Seeders;

use App\Models\S3Storage;
use Illuminate\Database\Seeder;

class S3StorageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        S3Storage::create([
            'name' => 'Local MinIO',
            'description' => 'Local MinIO S3 Storage',
            'key' => 'minioadmin',
            'secret' => 'minioadmin',
            'bucket' => 'local',
            'endpoint' => 'http://coolify-minio:9000',
            'team_id' => 0,
        ]);
    }
}

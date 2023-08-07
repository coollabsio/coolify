<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\S3Storage;

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
<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use Illuminate\Database\Seeder;

class LocalPersistentVolumeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        LocalPersistentVolume::create([
            'name' => 'test-pv',
            'mount_path' => '/data',
            'resource_id' => 1,
            'resource_type' => Application::class,
        ]);
    }
}

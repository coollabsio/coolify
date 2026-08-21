<?php

namespace Database\Factories;

use App\Enums\MigrationStorageDriver;
use App\Enums\ResourceMigrationDirection;
use App\Enums\ResourceMigrationStatus;
use App\Models\ResourceMigration;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceMigration>
 */
class ResourceMigrationFactory extends Factory
{
    protected $model = ResourceMigration::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'direction' => ResourceMigrationDirection::Export,
            'status' => ResourceMigrationStatus::Pending,
            'storage_driver' => MigrationStorageDriver::S3,
            'storage_config' => [
                'endpoint' => 'https://s3.example.com',
                'bucket' => 'migrations',
                'region' => 'us-east-1',
                'key' => 'test-key',
                'secret' => 'test-secret',
            ],
            'manifest' => null,
            'skip_data' => false,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function import(): static
    {
        return $this->state(fn (): array => [
            'direction' => ResourceMigrationDirection::Import,
        ]);
    }

    public function skipData(): static
    {
        return $this->state(fn (): array => [
            'skip_data' => true,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\ResourceMigrationStatus;
use App\Models\ResourceMigration;
use App\Models\ResourceMigrationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceMigrationItem>
 */
class ResourceMigrationItemFactory extends Factory
{
    protected $model = ResourceMigrationItem::class;

    public function definition(): array
    {
        return [
            'resource_migration_id' => ResourceMigration::factory(),
            'resource_type' => 'application',
            'source_uuid' => fake()->uuid(),
            'name' => fake()->words(2, true),
            'status' => ResourceMigrationStatus::Pending,
            'sort_order' => 0,
            'archives' => [],
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\InstanceMigrationStatus;
use App\Models\InstanceMigration;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstanceMigration>
 */
class InstanceMigrationFactory extends Factory
{
    protected $model = InstanceMigration::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'status' => InstanceMigrationStatus::Pending,
            'target_ip' => fake()->ipv4(),
            'target_port' => 22,
            'target_user' => 'root',
            'target_private_key_id' => PrivateKey::factory(),
            'old_host_ip' => fake()->ipv4(),
            'package_paths' => null,
            'phases' => [],
            'items' => [],
            'dry_run' => false,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function dryRun(): static
    {
        return $this->state(fn (): array => [
            'dry_run' => true,
        ]);
    }
}

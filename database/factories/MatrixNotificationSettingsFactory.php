<?php

namespace Database\Factories;

use App\Models\MatrixNotificationSettings;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MatrixNotificationSettings>
 */
class MatrixNotificationSettingsFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = MatrixNotificationSettings::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'matrix_enabled' => false,
            'matrix_homeserver_url' => null,
            'matrix_room_id' => null,
            'matrix_access_token' => null,
            'matrix_friendly_name' => null,

            'deployment_success_matrix_notifications' => false,
            'deployment_failure_matrix_notifications' => true,
            'status_change_matrix_notifications' => false,
            'backup_success_matrix_notifications' => false,
            'backup_failure_matrix_notifications' => true,
            'scheduled_task_success_matrix_notifications' => false,
            'scheduled_task_failure_matrix_notifications' => true,
            'docker_cleanup_success_matrix_notifications' => false,
            'docker_cleanup_failure_matrix_notifications' => true,
            'server_disk_usage_matrix_notifications' => true,
            'server_reachable_matrix_notifications' => false,
            'server_unreachable_matrix_notifications' => true,
            'server_patch_matrix_notifications' => false,
        ];
    }

    /**
     * Indicate that the Matrix notifications are enabled.
     */
    public function enabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'matrix_enabled' => true,
            'matrix_homeserver_url' => 'https://matrix.org',
            'matrix_room_id' => '!test:matrix.org',
            'matrix_access_token' => 'test_access_token',
            'matrix_friendly_name' => 'Test Matrix Setup',
        ]);
    }
}
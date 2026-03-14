<?php

namespace Database\Factories;

use App\Enums\ProjectMemberRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectMember>
 */
class ProjectMemberFactory extends Factory
{
    protected $model = ProjectMember::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => Project::factory(),
            'role' => fake()->randomElement(ProjectMemberRole::cases()),
            'permissions' => null,
            'invited_by' => null,
            'accepted_at' => now(),
        ];
    }

    /**
     * State for viewer role.
     */
    public function viewer(): static
    {
        return $this->state(fn () => ['role' => ProjectMemberRole::Viewer]);
    }

    /**
     * State for deployer role.
     */
    public function deployer(): static
    {
        return $this->state(fn () => ['role' => ProjectMemberRole::Deployer]);
    }

    /**
     * State for manager role.
     */
    public function manager(): static
    {
        return $this->state(fn () => ['role' => ProjectMemberRole::Manager]);
    }

    /**
     * State for pending invitation (not yet accepted).
     */
    public function pending(): static
    {
        return $this->state(fn () => ['accepted_at' => null]);
    }
}

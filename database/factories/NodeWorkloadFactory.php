<?php

namespace Database\Factories;

use App\Enums\NodeWorkloadDesiredState;
use App\Models\NodeWorkload;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class NodeWorkloadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(3, true),
            'desired_state' => NodeWorkloadDesiredState::RUNNING,
        ];
    }

    /** A preview of a pull request of the parent (decision 0025). */
    public function previewOf(NodeWorkload $parent, int $pullRequestId = 1, bool $fromFork = false): static
    {
        return $this->state(fn (): array => [
            'team_id' => $parent->team_id,
            'project_id' => $parent->project_id,
            'environment_id' => $parent->environment_id,
            'preview_of_id' => $parent->id,
            'pull_request_id' => $pullRequestId,
            'pull_request_ref' => "refs/pull/{$pullRequestId}/head",
            'pull_request_from_fork' => $fromFork,
        ]);
    }
}

<?php

namespace App\Actions\Project;

use App\Models\Project;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateProject
{
    use AsAction;

    public function handle(int $teamId, string $name, ?string $description = null): Project
    {
        return Project::create([
            'name' => $name,
            'description' => $description,
            'team_id' => $teamId,
        ]);
    }
}

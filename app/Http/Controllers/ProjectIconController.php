<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ProjectIconStorageService;
use Illuminate\Http\Response;

class ProjectIconController extends Controller
{
    public function __invoke(string $project_uuid, ProjectIconStorageService $iconStorage): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $contents = $iconStorage->projectContents($project);

        abort_if($contents === null, 404);

        return response($contents)->header('Content-Type', 'image/jpeg');
    }
}

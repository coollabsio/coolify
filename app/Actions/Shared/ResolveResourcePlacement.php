<?php

namespace App\Actions\Shared;

use App\Data\ResourcePlacement;
use App\Exceptions\AmbiguousDestinationException;
use App\Exceptions\EnvironmentNotFoundException;
use App\Exceptions\ProjectNotFoundException;
use App\Exceptions\ServerCannotHostResourcesException;
use App\Exceptions\ServerNotFoundException;
use App\Models\Project;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveResourcePlacement
{
    use AsAction;

    public function handle(
        int $teamId,
        string $projectUuid,
        ?string $environmentName,
        ?string $environmentUuid,
        string $serverUuid,
        ?string $destinationUuid,
    ): ResourcePlacement {
        $project = Project::whereTeamId($teamId)->whereUuid($projectUuid)->first();
        if (! $project) {
            throw new ProjectNotFoundException($projectUuid);
        }

        $environment = null;
        if ($environmentName) {
            $environment = $project->environments()->where('name', $environmentName)->first();
        }
        if (! $environment && $environmentUuid) {
            $environment = $project->environments()->where('uuid', $environmentUuid)->first();
        }
        if (! $environment) {
            throw new EnvironmentNotFoundException;
        }

        $server = Server::whereTeamId($teamId)->whereUuid($serverUuid)->first();
        if (! $server) {
            throw new ServerNotFoundException($serverUuid);
        }
        if (! $server->canHostResources()) {
            throw new ServerCannotHostResourcesException;
        }

        $destinations = $server->destinations();
        if ($destinationUuid) {
            $destination = $destinations->firstWhere('uuid', $destinationUuid);
            if (! $destination) {
                throw new AmbiguousDestinationException;
            }
        } elseif ($destinations->count() === 1) {
            $destination = $destinations->first();
        } else {
            throw new AmbiguousDestinationException;
        }

        return new ResourcePlacement($project, $environment, $server, $destination);
    }
}

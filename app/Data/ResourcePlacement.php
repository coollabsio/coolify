<?php

namespace App\Data;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;

readonly class ResourcePlacement
{
    public function __construct(
        public Project $project,
        public Environment $environment,
        public Server $server,
        public Model $destination, // StandaloneDocker|SwarmDocker
    ) {}
}

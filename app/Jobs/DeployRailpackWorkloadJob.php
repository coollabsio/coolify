<?php

namespace App\Jobs;

use App\Models\NodeBuild;
use App\Traits\BuildsNodeWorkloadImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Builds a cluster application with Railpack, which detects the language and
 * framework of the repository (static sites too), then deploys it.
 */
class DeployRailpackWorkloadJob implements ShouldQueue
{
    use BuildsNodeWorkloadImage, Queueable, SerializesModels;

    protected function source(NodeBuild $build): array
    {
        return ['source_type' => 'railpack'];
    }
}

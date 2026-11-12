<?php

namespace App\Jobs;

use App\Enums\NodeWorkloadSourceType;
use App\Models\NodeBuild;
use App\Traits\BuildsNodeWorkloadImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Builds a cluster application from the Dockerfile in its Git repository, or from the
 * Dockerfile that the user pasted with an empty context, then deploys it.
 */
class DeployDockerfileWorkloadJob implements ShouldQueue
{
    use BuildsNodeWorkloadImage, Queueable, SerializesModels;

    protected function source(NodeBuild $build): array
    {
        if ($build->source_type === NodeWorkloadSourceType::DOCKERFILE_INLINE) {
            return ['source_type' => 'dockerfile', 'dockerfile_content' => (string) $build->dockerfile];
        }

        return [
            'source_type' => 'dockerfile',
            'dockerfile_path' => ltrim((string) ($build->dockerfile_location ?: 'Dockerfile'), '/'),
        ];
    }
}

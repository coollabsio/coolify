<?php

namespace App\Actions\Node;

use App\Models\InstanceSettings;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class BuildContainerLabels
{
    use AsAction;

    /** @return array<string, string> */
    public function handle(NodeWorkload $workload, NodeWorkloadRevision $revision, string $component): array
    {
        if ($revision->node_workload_id !== $workload->id) {
            throw new InvalidArgumentException('The revision does not belong to the workload.');
        }
        if (blank($component)) {
            throw new InvalidArgumentException('The workload component is required.');
        }

        return array_filter([
            'coolify.managed' => 'true',
            'coolify.instance' => InstanceSettings::get()->ensureInstanceUuid(),
            'coolify.workload' => $workload->uuid,
            'coolify.revision' => $revision->uuid,
            'coolify.component' => $component,
            'coolify.project' => $workload->project?->uuid,
            'coolify.environment' => $workload->environment?->uuid,
        ], fn (?string $value): bool => filled($value));
    }
}

<?php

namespace App\Services\Kubernetes;

use Illuminate\Support\Collection;
use JsonException;

class KubernetesApplicationStatusResolver
{
    /**
     * @throws JsonException
     */
    public function resolve(string $deploymentJson, string $podsJson): string
    {
        $deployment = json_decode($deploymentJson, associative: true, flags: JSON_THROW_ON_ERROR);
        $pods = collect(data_get(json_decode($podsJson, associative: true, flags: JSON_THROW_ON_ERROR), 'items', []));
        $desiredReplicas = (int) data_get($deployment, 'spec.replicas', 1);
        $availableReplicas = (int) data_get($deployment, 'status.availableReplicas', 0);
        $readyReplicas = (int) data_get($deployment, 'status.readyReplicas', 0);

        if ($desiredReplicas === 0) {
            return 'exited';
        }

        if ($this->hasFailedPods($pods)) {
            return $availableReplicas > 0 ? 'degraded:unhealthy' : 'exited:unhealthy';
        }

        if ($availableReplicas >= $desiredReplicas && $readyReplicas >= $desiredReplicas && $this->allPodsReady($pods)) {
            return 'running:healthy';
        }

        if ($availableReplicas > 0 || $readyReplicas > 0) {
            return 'degraded:unhealthy';
        }

        if ($this->hasStartingPods($pods)) {
            return 'starting:unhealthy';
        }

        return 'exited';
    }

    private function allPodsReady(Collection $pods): bool
    {
        if ($pods->isEmpty()) {
            return false;
        }

        return $pods->every(function (array $pod) {
            $statuses = collect(data_get($pod, 'status.containerStatuses', []));

            return $statuses->isNotEmpty() && $statuses->every(fn (array $container) => data_get($container, 'ready') === true);
        });
    }

    private function hasFailedPods(Collection $pods): bool
    {
        return $pods->contains(function (array $pod) {
            if (in_array(data_get($pod, 'status.phase'), ['Failed', 'Unknown'], true)) {
                return true;
            }

            return collect(data_get($pod, 'status.containerStatuses', []))
                ->contains(fn (array $container) => in_array(data_get($container, 'state.waiting.reason'), [
                    'CrashLoopBackOff',
                    'CreateContainerConfigError',
                    'CreateContainerError',
                    'ErrImagePull',
                    'ImagePullBackOff',
                    'InvalidImageName',
                ], true));
        });
    }

    private function hasStartingPods(Collection $pods): bool
    {
        return $pods->contains(fn (array $pod) => in_array(data_get($pod, 'status.phase'), ['Pending', 'Running'], true));
    }
}

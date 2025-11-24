<?php

namespace App\Traits;

use App\Services\ContainerStatusAggregator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Exception\ParseException;

trait CalculatesExcludedStatus
{
    /**
     * Calculate status for containers when all containers are excluded from health checks.
     *
     * This method processes excluded containers and returns a status with :excluded suffix
     * to indicate that monitoring is disabled but still show the actual container state.
     *
     * @param  Collection  $containers  Collection of container objects from Docker inspect
     * @param  Collection  $excludedContainers  Collection of container names that are excluded
     * @return string Status string with :excluded suffix (e.g., 'running:unhealthy:excluded')
     */
    protected function calculateExcludedStatus(Collection $containers, Collection $excludedContainers): string
    {
        // Filter to only excluded containers
        $excludedOnly = $containers->filter(function ($container) use ($excludedContainers) {
            $labels = data_get($container, 'Config.Labels', []);
            $serviceName = data_get($labels, 'com.docker.compose.service');

            return $serviceName && $excludedContainers->contains($serviceName);
        });

        // Use ContainerStatusAggregator service for state machine logic
        $aggregator = new ContainerStatusAggregator;
        $status = $aggregator->aggregateFromContainers($excludedOnly);

        // Append :excluded suffix
        return $this->appendExcludedSuffix($status);
    }

    /**
     * Calculate status for containers when all containers are excluded (simplified version).
     *
     * This version works with status strings (e.g., "running:healthy") instead of full
     * container objects, suitable for Sentinel updates that don't have full container data.
     *
     * @param  Collection  $containerStatuses  Collection of status strings keyed by container name
     * @return string Status string with :excluded suffix
     */
    protected function calculateExcludedStatusFromStrings(Collection $containerStatuses): string
    {
        // Use ContainerStatusAggregator service for state machine logic
        $aggregator = new ContainerStatusAggregator;
        $status = $aggregator->aggregateFromStrings($containerStatuses);

        // Append :excluded suffix
        $finalStatus = $this->appendExcludedSuffix($status);

        return $finalStatus;
    }

    /**
     * Append :excluded suffix to a status string.
     *
     * Converts status formats like:
     * - "running:healthy" → "running:healthy:excluded"
     * - "degraded:unhealthy" → "degraded:excluded" (simplified)
     * - "paused:unknown" → "paused:excluded" (simplified)
     *
     * @param  string  $status  The base status string
     * @return string Status with :excluded suffix
     */
    private function appendExcludedSuffix(string $status): string
    {
        // For degraded states, simplify to just "degraded:excluded"
        if (str($status)->startsWith('degraded')) {
            return 'degraded:excluded';
        }

        // For paused/starting/exited states, simplify to just "state:excluded"
        if (str($status)->startsWith('paused')) {
            return 'paused:excluded';
        }

        if (str($status)->startsWith('starting')) {
            return 'starting:excluded';
        }

        if (str($status)->startsWith('exited')) {
            return 'exited';
        }

        // For running states, keep the health status: "running:healthy:excluded"
        return "$status:excluded";
    }

    /**
     * Get excluded containers from docker-compose YAML.
     *
     * Containers are excluded if:
     * - They have exclude_from_hc: true label
     * - They have restart: no policy
     *
     * @param  string|null  $dockerComposeRaw  The raw docker-compose YAML content
     * @return Collection Collection of excluded container names
     */
    protected function getExcludedContainersFromDockerCompose(?string $dockerComposeRaw): Collection
    {
        $excludedContainers = collect();

        if (! $dockerComposeRaw) {
            return $excludedContainers;
        }

        try {
            $dockerCompose = \Symfony\Component\Yaml\Yaml::parse($dockerComposeRaw);

            // Validate structure
            if (! is_array($dockerCompose)) {
                Log::warning('Docker Compose YAML did not parse to array', [
                    'yaml_length' => strlen($dockerComposeRaw),
                    'parsed_type' => gettype($dockerCompose),
                ]);

                return $excludedContainers;
            }

            $services = data_get($dockerCompose, 'services', []);

            if (! is_array($services)) {
                Log::warning('Docker Compose services is not an array', [
                    'services_type' => gettype($services),
                ]);

                return $excludedContainers;
            }

            foreach ($services as $serviceName => $serviceConfig) {
                $excludeFromHc = data_get($serviceConfig, 'exclude_from_hc', false);
                $restartPolicy = data_get($serviceConfig, 'restart', 'always');

                if ($excludeFromHc || $restartPolicy === 'no') {
                    $excludedContainers->push($serviceName);
                }
            }
        } catch (ParseException $e) {
            // Specific YAML parsing errors
            Log::warning('Failed to parse Docker Compose YAML for health check exclusions', [
                'error' => $e->getMessage(),
                'line' => $e->getParsedLine(),
                'snippet' => $e->getSnippet(),
            ]);

            return $excludedContainers;
        } catch (\Exception $e) {
            // Unexpected errors
            Log::error('Unexpected error parsing Docker Compose YAML', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $excludedContainers;
        }

        return $excludedContainers;
    }
}

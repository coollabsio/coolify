<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalConnection;
use App\Services\Infisical\InfisicalLock;
use Lorisleiva\Actions\Concerns\AsAction;

class AdoptTeamSecretsIntoInfisical
{
    use AsAction;

    /**
     * Push every existing Coolify variable in the team up into Infisical, once.
     *
     * Idempotent: the upsert endpoint tolerates re-running, folder creation
     * tolerates an already-exists response, and environment creation is skipped
     * when it already exists. A partial failure can therefore be retried
     * safely.
     *
     * adopted_at is stamped ONLY on a run where no environment was skipped,
     * so a permissions problem does not silently mark adoption complete.
     *
     * @return array{pushed: int, skippedEnvironments: array<int, string>}
     */
    public function handle(InfisicalConnection $connection): array
    {
        $client = $connection->client();
        $projectId = $connection->infisical_project_id;

        $buckets = CollectTeamSecrets::run($connection->team);

        $existing = $client->listEnvironmentSlugs($projectId);
        $needed = collect($buckets)->pluck('environment')->unique()->values();
        $skipped = [];

        foreach ($needed as $slug) {
            if (in_array($slug, $existing, true)) {
                continue;
            }

            if (! $client->createEnvironment($projectId, $slug, $slug)) {
                $skipped[] = $slug;
            }
        }

        $pushed = 0;

        foreach ($buckets as $bucket) {
            if (in_array($bucket['environment'], $skipped, true)) {
                continue;
            }

            if ($bucket['secrets'] === []) {
                continue;
            }

            $client->ensureFolderPath($projectId, $bucket['environment'], $bucket['path']);
            $client->upsertSecrets($projectId, $bucket['environment'], $bucket['path'], $bucket['secrets']);

            $pushed += count($bucket['secrets']);

            // Mark provenance. These are system writes: the lock is already
            // armed by the time adoption runs.
            InfisicalLock::asSystem(function () use ($bucket): void {
                foreach ($bucket['rows'] as $row) {
                    $row->forceFill([
                        'is_infisical_managed' => true,
                        'infisical_path' => $bucket['path'],
                    ])->save();
                }
            });
        }

        $connection->forceFill([
            'last_synced_at' => now(),
            'last_sync_status' => InfisicalConnection::STATUS_SUCCESS,
            'last_sync_error' => $skipped === [] ? null
                : 'Could not create Infisical environment(s): '.implode(', ', $skipped),
            'adopted_at' => $skipped === [] ? now() : null,
        ])->save();

        return ['pushed' => $pushed, 'skippedEnvironments' => $skipped];
    }
}

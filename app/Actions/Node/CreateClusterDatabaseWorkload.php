<?php

namespace App\Actions\Node;

use App\Enums\NodeDatabaseType;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadSourceType;
use App\Models\Environment;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Creates a database on a cluster server (decision 0010): the engine image, generated credentials,
 * one data volume, an internal DNS name, and a health check, without domains. Like a v4 database,
 * it stays stopped until the user deploys it.
 */
class CreateClusterDatabaseWorkload
{
    use AsAction;

    public function handle(
        Project $project,
        Environment $environment,
        NodeCluster $cluster,
        NodeDatabaseType $type,
        User $requestedBy,
        ?Node $targetNode = null,
        ?string $image = null,
    ): NodeWorkload {
        if ($environment->project_id !== $project->id) {
            throw new RuntimeException('The environment does not belong to this project.');
        }
        if ($cluster->team_id !== $project->team_id) {
            throw new RuntimeException('The cluster does not belong to this project team.');
        }
        if (! $requestedBy->isAdminOfTeam($project->team_id)) {
            throw new RuntimeException('The user cannot create resources for this project team.');
        }
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }
        $image = filled($image) ? trim($image) : $type->defaultImage();

        return DB::transaction(function () use ($project, $environment, $cluster, $type, $targetNode, $image): NodeWorkload {
            $node = CreateClusterDockerImageWorkload::placementNode($cluster, $targetNode);

            $workload = NodeWorkload::query()->create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'environment_id' => $environment->id,
                'name' => CreateClusterDockerImageWorkload::uniqueName($environment, $type->value.'-database'),
                'source_type' => NodeWorkloadSourceType::IMAGE,
                'database_type' => $type,
                'desired_state' => NodeWorkloadDesiredState::STOPPED,
                ...$type->healthCheckSettings(),
            ]);
            $workload->createRevision(
                CreateClusterDockerImageWorkload::qualifiedImage($image),
                array_filter(['command' => $type->startCommand(), 'restart_policy' => 'unless-stopped']),
            );
            foreach ($type->credentials() as $credential) {
                self::storeCredential($workload, $credential, $credential['default'] ?? Str::password(length: 64, symbols: false));
            }
            $workload->nodes()->attach($node);
            CreateNodeVolume::run($workload, $type->value.'-data', $type->dataDirectory($image));
            EnsureNodeWorkloadDnsNames::run($node);

            return $workload->refresh();
        });
    }

    /**
     * Saves a credential variable. NodeWorkload::runtimeEnvironment() adds the `<key>_FILE` variable of a secret file.
     *
     * @param  array{key: string, label: string, default: ?string, file: bool}  $credential
     */
    public static function storeCredential(NodeWorkload $workload, array $credential, string $value): void
    {
        $variable = $workload->environment_variables()->where('key', $credential['key'])->first();
        if ($variable === null) {
            $workload->environment_variables()->create([
                'key' => $credential['key'],
                'value' => $value,
                'is_literal' => true,
                'is_runtime' => true,
                'is_buildtime' => false,
                'is_preview' => false,
                'is_file_secret' => $credential['file'],
            ]);
        } elseif ($variable->value !== $value) {
            $variable->update(['value' => $value]);
        }
    }
}

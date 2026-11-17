<?php

namespace App\Notifications\Node;

use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Notifications\CustomEmailNotification;
use App\Traits\IncludesDeploymentCommitDetails;

/**
 * The data of a deploy notification of a cluster application, preview, or stack, with the same
 * texts and team settings as v4 DeploymentSuccess and DeploymentFailed. It keeps only strings, so
 * the queued notification does not load a model again.
 */
abstract class ClusterDeploymentNotification extends CustomEmailNotification
{
    use IncludesDeploymentCommitDetails;

    public string $application_name;

    public string $application_uuid;

    public ?string $project_name;

    public ?string $environment_name;

    public ?string $fqdn;

    /** The pull request of a preview, or null. */
    public ?int $pull_request_id = null;

    public ?string $preview_fqdn = null;

    public function __construct(NodeWorkload|NodeStack $resource, public string $deployment_uuid, public string $deployment_url, ?string $commitSha = null)
    {
        $this->onQueue('high');
        $parent = $resource instanceof NodeWorkload && $resource->isPreview() ? $resource->previewOf : null;
        $this->application_name = (string) ($parent ?? $resource)->name;
        $this->application_uuid = $resource->uuid;
        $this->project_name = $resource->project?->name;
        $this->environment_name = $resource->environment?->name;
        $this->fqdn = $resource instanceof NodeWorkload ? self::domain($parent ?? $resource) : null;
        if ($parent !== null) {
            $this->pull_request_id = $resource->pull_request_id;
            $this->preview_fqdn = self::domain($resource);
        }
        if ($commitSha !== null && preg_match('/^[0-9a-f]{7,64}$/i', $commitSha) === 1) {
            $this->commit_sha = $commitSha;
            $branch = $resource->git_branch ?? null;
            $this->commit_branch = $parent === null && filled($branch) ? (string) $branch : null;
        }
    }

    /** The first domain of a workload, like the first FQDN of a v4 application. */
    private static function domain(NodeWorkload $workload): ?string
    {
        $route = $workload->domain_routes[0] ?? null;

        return $route === null ? null : "{$route['scheme']}://{$route['host']}".($route['path'] ?? '');
    }

    protected function isPreview(): bool
    {
        return $this->pull_request_id !== null;
    }
}

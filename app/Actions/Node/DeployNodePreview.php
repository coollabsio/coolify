<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\CancelNodeBuild;
use App\Actions\Node\Build\StartNodeBuild;
use App\Enums\NodeWorkloadDesiredState;
use App\Jobs\UpdateNodePreviewCommentJob;
use App\Models\EnvironmentVariable;
use App\Models\Node;
use App\Models\NodeBuild;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Deploys a pull request of a Git application as a preview workload (decision 0025). It finds or
 * creates the preview, copies the source, build and health check settings of the parent, places a
 * new preview on the first Node of the parent with empty volumes, writes the resolved preview
 * variables, and builds the commit. An active build of the preview at another commit is
 * cancelled, because only the newest commit of a pull request matters.
 *
 * A webhook passes the head SHA and no user; a manual deploy passes the user and may leave the
 * commit null, so the build resolves `$ref`. The caller decides `$fromFork` with the checks of its
 * provider; a manual deploy passes false, as in v4.
 */
class DeployNodePreview
{
    use AsAction;

    /** The values a preview takes from its parent at each deploy. */
    private const COPIED_COLUMNS = [
        'team_id', 'project_id', 'environment_id',
        'source_type', 'git_repository', 'git_branch', 'github_app_id', 'private_key_id',
        'base_directory', 'dockerfile_location', 'build_target', 'registry_credential_id',
        'include_coolify_default_variables', 'redact_all_env_values_in_logs', 'is_env_sorting_enabled',
        'health_check_enabled', 'health_check_type', 'health_check_command', 'health_check_method',
        'health_check_scheme', 'health_check_host', 'health_check_port', 'health_check_path',
        'health_check_return_code', 'health_check_response_text', 'health_check_interval',
        'health_check_timeout', 'health_check_retries', 'health_check_start_period', 'custom_healthcheck_found',
    ];

    /** The variable columns that a preview copies from the resolved variables of its parent. */
    private const VARIABLE_COLUMNS = [
        'value', 'comment', 'order', 'is_multiline', 'is_literal', 'is_runtime', 'is_buildtime',
        'is_shown_once', 'is_required', 'is_file_secret', 'secret_file_uid', 'secret_file_gid', 'secret_file_mode',
        'uses_legacy_escaping', 'preserve_compose_null',
    ];

    /**
     * @param  string  $ref  The ref that has the head commit: `refs/pull/<id>/head`, `refs/merge-requests/<id>/head`, or the source branch of Bitbucket.
     * @return array{preview: NodeWorkload, build: NodeBuild, domain_error: ?string} `domain_error` says why a new preview has no domain.
     */
    public function handle(
        NodeWorkload $parent,
        int $pullRequestId,
        ?string $commitSha,
        string $ref,
        ?string $url = null,
        bool $fromFork = false,
        ?User $requestedBy = null,
    ): array {
        if ($requestedBy !== null) {
            Gate::forUser($requestedBy)->authorize('update', $parent);
        }
        $parent = NodeWorkload::query()->withoutGlobalScopes()->findOrFail($parent->id);
        $this->ensureDeployable($parent, $pullRequestId, $commitSha, $ref, $fromFork);
        $node = $parent->nodes()->orderBy('nodes.id')->first();
        $cluster = $node?->node_cluster_id === null ? null : NodeCluster::query()->find($node->node_cluster_id);
        if ($node === null || $cluster === null) {
            throw new RuntimeException('The application has no server in a cluster. Deploy it first.');
        }
        if (! in_array($cluster->network_status, ['active', 'degraded'], true)) {
            throw new RuntimeException('The cluster network is not ready.');
        }

        [$preview, $created, $forkChanged] = DB::transaction(function () use ($parent, $node, $pullRequestId, $ref, $url, $fromFork): array {
            NodeWorkload::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($parent->id);
            $preview = NodeWorkload::query()->withoutGlobalScopes()
                ->where('preview_of_id', $parent->id)
                ->where('pull_request_id', $pullRequestId)
                ->lockForUpdate()
                ->first();
            if ($preview?->desired_state === NodeWorkloadDesiredState::REMOVED) {
                throw new RuntimeException("The preview of pull request #{$pullRequestId} is being removed. Deploy it again when the removal finishes.");
            }
            $values = [
                ...$parent->only(self::COPIED_COLUMNS),
                // A manual deploy has no URL; it keeps the URL from an earlier webhook.
                'pull_request_url' => $url ?? $preview?->pull_request_url,
                'pull_request_ref' => $ref,
                'pull_request_from_fork' => $fromFork,
            ];
            $created = $preview === null;
            $forkChanged = ! $created && $preview->pull_request_from_fork !== $fromFork;
            if ($created) {
                $preview = NodeWorkload::query()->create([
                    ...$values,
                    'preview_of_id' => $parent->id,
                    'pull_request_id' => $pullRequestId,
                    'name' => Str::limit($parent->name, 240, '')."-pr-{$pullRequestId}",
                    'desired_state' => NodeWorkloadDesiredState::STOPPED,
                    ...$parent->only(['www_redirect', 'force_https', 'permanent_redirect']),
                ]);
                $preview->nodes()->attach($node);
                EnsureNodeWorkloadDnsNames::run($node);
            } else {
                $preview->update($values);
            }
            $this->createMissingVolumes($parent, $preview);
            $this->writeVariables($preview, $parent->resolvedPreviewVariables($fromFork));

            return [$preview->refresh(), $created, $forkChanged];
        });

        $domainError = $created ? $this->applyDomains($parent, $preview, $cluster, $requestedBy) : null;
        if ($forkChanged) {
            // The outbound allows of the parent follow the fork flag (decision 0025).
            QueueNodeClusterNetworkRevision::run($cluster, $requestedBy);
        }

        $build = $this->build($preview, $commitSha, $requestedBy);
        if ($build->wasRecentlyCreated) {
            UpdateNodePreviewCommentJob::dispatchFor($preview, $build);
        }

        return ['preview' => $preview, 'build' => $build, 'domain_error' => $domainError];
    }

    /**
     * Each host of the parent routes with the template applied, keeping scheme, path, and port.
     * Without a domain, `{{domain}}` is `<parent uuid>.<generated domain base>` on the port of the
     * health check, else 80.
     *
     * @return list<array{scheme: string, host: string, port: int|string|null, path: string, noindex: bool}>
     */
    public static function previewRoutes(NodeWorkload $parent, int $pullRequestId, NodeCluster $cluster): array
    {
        $template = filled($parent->preview_url_template) ? $parent->preview_url_template : NodeWorkload::DEFAULT_PREVIEW_URL_TEMPLATE;
        $random = Str::lower(Str::random(8));
        $host = fn (string $domain): string => str_replace(['{{domain}}', '{{pr_id}}', '{{random}}'], [$domain, (string) $pullRequestId, $random], $template);
        if ($parent->hasIngressRoutes()) {
            return array_map(fn (array $route): array => UpdateNodeWorkloadDomains::normalizeRoute([...$route, 'host' => $host((string) $route['host'])]), $parent->domain_routes);
        }
        $base = $cluster->generatedDomainBase();
        if ($base === null) {
            return [];
        }

        return [UpdateNodeWorkloadDomains::normalizeRoute([
            'scheme' => $cluster->supportsIngressTls() ? 'https' : 'http',
            'host' => $host("{$parent->uuid}.{$base}"),
            'port' => (int) ($parent->health_check_port ?: 80),
            'path' => '',
        ])];
    }

    /** An owner or admin of the team sets what no user asked for, like a stack (decision 0023). */
    public static function actor(int $teamId, ?User $requestedBy): User
    {
        if ($requestedBy?->isAdminOfTeam($teamId)) {
            return $requestedBy;
        }
        $admin = Team::query()->find($teamId)?->members()
            ->wherePivotIn('role', ['owner', 'admin'])
            ->orderByRaw("CASE WHEN team_user.role = 'owner' THEN 0 ELSE 1 END")
            ->orderBy('users.id')
            ->first();

        return $admin instanceof User ? $admin : throw new RuntimeException('The team has no owner or admin to set the domains of the preview.');
    }

    private function ensureDeployable(NodeWorkload $parent, int $pullRequestId, ?string $commitSha, string $ref, bool $fromFork): void
    {
        $error = match (true) {
            ! $parent->canHavePreviews() => 'Only a Git application can have previews.',
            $parent->desired_state === NodeWorkloadDesiredState::REMOVED => 'The application is being deleted.',
            ! $parent->preview_deployments_enabled => 'Preview deployments are off for this application.',
            $fromFork && ! $parent->fork_preview_deployments_enabled => 'Preview deployments of pull requests from forks are off for this application.',
            $pullRequestId < 1 => 'The pull request number must be 1 or more.',
            blank($ref) || str_contains($ref, '..') || preg_match('/[\s\x00-\x1f~^:?*\[\\\\]/', $ref) === 1 => 'The ref of the pull request is not valid.',
            $commitSha !== null && preg_match('/\A([0-9a-f]{40}|[0-9a-f]{64})\z/', $commitSha) !== 1 => 'The commit must be a full SHA.',
            default => null,
        };
        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    /** A new empty volume for each volume of the parent, at the same path (decision 0025). */
    private function createMissingVolumes(NodeWorkload $parent, NodeWorkload $preview): void
    {
        $paths = $preview->volumes()->whereNull('detached_at')->pluck('mount_path')->all();
        $parent->volumes()->whereNull('detached_at')->orderBy('id')->get()
            ->reject(fn ($volume): bool => in_array($volume->mount_path, $paths, true))
            ->each(fn ($volume) => CreateNodeVolume::run($preview, $volume->name, $volume->mount_path));
    }

    /**
     * The resolved variables become the variables of the preview, so its build and deploy read them
     * as for any workload. A key that is not resolved anymore is removed.
     *
     * @param  Collection<string, EnvironmentVariable>  $resolved
     */
    private function writeVariables(NodeWorkload $preview, Collection $resolved): void
    {
        $preview->environment_variables()->whereNotIn('key', $resolved->keys()->all())->get()
            ->each(fn (EnvironmentVariable $variable) => $variable->withoutAuditLogging(fn () => $variable->delete()));
        $existing = $preview->environment_variables()->get()->keyBy('key');
        foreach ($resolved as $key => $source) {
            $variable = $existing->get($key) ?? (new EnvironmentVariable)->forceFill([
                'key' => $key,
                'resourceable_type' => $preview->getMorphClass(),
                'resourceable_id' => $preview->id,
                'is_preview' => false,
            ]);
            $variable->forceFill($source->only(self::VARIABLE_COLUMNS));
            if ($variable->isDirty()) {
                $variable->withoutAuditLogging(fn () => $variable->save());
            }
        }
    }

    private function applyDomains(NodeWorkload $parent, NodeWorkload $preview, NodeCluster $cluster, ?User $requestedBy): ?string
    {
        $routes = self::previewRoutes($parent, (int) $preview->pull_request_id, $cluster);
        if ($routes === []) {
            return 'The preview has no domain: the application has no domain and the cluster has no ingress server for a generated domain.';
        }
        try {
            UpdateNodeWorkloadDomains::run($preview, $routes, self::actor($preview->team_id, $requestedBy));
        } catch (ValidationException $exception) {
            return 'The preview has no domain: '.collect($exception->errors())->flatten()->first();
        }

        return null;
    }

    private function build(NodeWorkload $preview, ?string $commitSha, ?User $requestedBy): NodeBuild
    {
        $active = $preview->builds()->active()->latest('id')->first();
        if ($active !== null && $commitSha !== null && $active->commit_sha === $commitSha) {
            return $active;
        }
        if ($active !== null) {
            CancelNodeBuild::run($active, 'Cancelled by a newer commit of the pull request.');
        }

        return StartNodeBuild::run($preview, $requestedBy, $commitSha);
    }
}

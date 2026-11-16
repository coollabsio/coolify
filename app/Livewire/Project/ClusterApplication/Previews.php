<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\CreateLifecycleOperation;
use App\Actions\Node\DeployNodePreview;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\RemoveNodePreview;
use App\Actions\Node\UpdateNodeWorkloadDomains;
use App\Enums\NodeWorkloadAction;
use App\Enums\NodeWorkloadDesiredState;
use App\Enums\NodeWorkloadState;
use App\Jobs\ManageNodeWorkloadJob;
use App\Models\NodeContainer;
use App\Models\NodeWorkload;
use App\Support\DomainUrlParts;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The Previews tab of a Git cluster application (decision 0025), laid out like the v4 Previews tab:
 * the preview settings, the URL template, a manual deploy, and one card per preview.
 */
class Previews extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public NodeWorkload $workload;

    public bool $isPreviewDeploymentsEnabled = false;

    public bool $isPrDeploymentsPublicEnabled = false;

    public bool $previewUsesProductionValues = true;

    public string $previewUrlTemplate = NodeWorkload::DEFAULT_PREVIEW_URL_TEMPLATE;

    public string $pullRequestNumber = '';

    /**
     * Open pull requests of a GitHub App source, as in v4. Locked: a deploy takes the link from here.
     *
     * @var list<array{number: int, title: string, html_url: string, from_fork: bool}>
     */
    #[Locked]
    public array $pullRequests = [];

    #[Locked]
    public ?int $rateLimitRemaining = null;

    /** @var array<string, string> The domain field of each preview, by preview uuid. */
    public array $previewDomains = [];

    /** Why the last manual deploy has no domain. */
    public ?string $domainError = null;

    public function mount(NodeWorkload $workload): void
    {
        $this->authorize('view', $workload);
        abort_unless($workload->canHavePreviews(), 404);
        $this->workload = $workload;
        $this->fillSettings();
    }

    public function togglePreviewDeployments(): void
    {
        $this->isPreviewDeploymentsEnabled = ! $this->workload->preview_deployments_enabled;
        $this->savePreviewSettings();
    }

    public function savePreviewSettings(): void
    {
        try {
            $this->authorize('update', $this->workload);
            $this->validate([
                'isPreviewDeploymentsEnabled' => ['boolean'],
                'isPrDeploymentsPublicEnabled' => ['boolean'],
                'previewUsesProductionValues' => ['boolean'],
            ]);
            $this->workload->update([
                'preview_deployments_enabled' => $this->isPreviewDeploymentsEnabled,
                'fork_preview_deployments_enabled' => $this->isPrDeploymentsPublicEnabled,
                'preview_uses_production_values' => $this->previewUsesProductionValues,
            ]);
            $this->dispatch('success', 'Preview settings updated.');
        } catch (Throwable $exception) {
            $this->fillSettings();

            handleError($exception, $this);
        }
    }

    public function saveUrlTemplate(): void
    {
        try {
            $this->authorize('update', $this->workload);
            $this->previewUrlTemplate = str_replace(' ', '', $this->previewUrlTemplate);
            $this->validate([
                'previewUrlTemplate' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9.{}_-]+\z/i', 'regex:/\{\{domain\}\}/', 'regex:/\{\{(pr_id|random)\}\}/'],
            ], [
                'previewUrlTemplate.regex' => 'The template needs the domain placeholder and the pr_id or random placeholder. Use only letters, digits, dots, hyphens, and placeholders.',
            ]);
            $this->workload->update(['preview_url_template' => $this->previewUrlTemplate]);
            $this->dispatch('success', 'Preview URL template updated. It applies to new previews.');
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function resetUrlTemplate(): void
    {
        $this->previewUrlTemplate = NodeWorkload::DEFAULT_PREVIEW_URL_TEMPLATE;
        $this->saveUrlTemplate();
    }

    /** A manual deploy is the decision of the user, so it gets the values of a same-repository pull request, as in v4. */
    public function deployPullRequest(): void
    {
        $this->domainError = null;
        try {
            $this->authorize('update', $this->workload);
            $number = (int) $this->validate(['pullRequestNumber' => ['required', 'integer', 'min:1', 'max:2147483647']])['pullRequestNumber'];
            ['build' => $build, 'domain_error' => $this->domainError] = DeployNodePreview::run($this->workload, $number, null, $this->pullRequestRef($number), requestedBy: auth()->user());
            $this->pullRequestNumber = '';
            $this->dispatch('success', $build->wasRecentlyCreated ? "Preview build of pull request #{$number} queued." : "A build of pull request #{$number} is already running.");
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function loadPullRequests(): void
    {
        try {
            $this->authorize('update', $this->workload);
            $githubApp = $this->workload->githubApp;
            if ($githubApp === null) {
                throw new RuntimeException('Loading pull requests is only supported for GitHub App sources.');
            }
            ['rate_limit_remaining' => $this->rateLimitRemaining, 'data' => $data] = githubApi(source: $githubApp, endpoint: '/repos/'.gitRepositorySlug($this->workload->git_repository).'/pulls');
            $this->pullRequests = collect($data)
                ->map(fn ($pullRequest): array => [
                    'number' => (int) data_get($pullRequest, 'number'),
                    'title' => (string) data_get($pullRequest, 'title'),
                    'html_url' => (string) data_get($pullRequest, 'html_url'),
                    'from_fork' => data_get($pullRequest, 'head.repo.id') !== data_get($pullRequest, 'base.repo.id'),
                ])
                ->sortBy('number')
                ->values()
                ->all();
        } catch (Throwable $exception) {
            $this->rateLimitRemaining = 0;
            handleError($exception, $this);
        }
    }

    /** Deploys a loaded pull request. Like the manual deploy, it is the decision of the user (v4). */
    public function deployLoadedPullRequest(int $number): void
    {
        $this->domainError = null;
        try {
            $this->authorize('update', $this->workload);
            $pullRequest = collect($this->pullRequests)->firstWhere('number', $number)
                ?? throw new RuntimeException("Pull request #{$number} is not in the loaded list. Load the pull requests again.");
            ['build' => $build, 'domain_error' => $this->domainError] = DeployNodePreview::run($this->workload, $number, null, $this->pullRequestRef($number), $pullRequest['html_url'], requestedBy: auth()->user());
            $this->dispatch('success', $build->wasRecentlyCreated ? "Preview build of pull request #{$number} queued." : "A build of pull request #{$number} is already running.");
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    /** Builds the latest commit of the stored ref again. The domains of the preview stay as they are. */
    public function redeploy(string $previewUuid): void
    {
        try {
            $preview = $this->preview($previewUuid, 'deploy');
            ['build' => $build] = DeployNodePreview::run(
                $this->workload,
                (int) $preview->pull_request_id,
                null,
                $preview->pull_request_ref ?: $this->pullRequestRef((int) $preview->pull_request_id),
                $preview->pull_request_url,
                (bool) $preview->pull_request_from_fork,
                auth()->user(),
            );
            $this->dispatch('success', $build->wasRecentlyCreated ? "Preview build of pull request #{$preview->pull_request_id} queued." : "A build of pull request #{$preview->pull_request_id} is already running.");
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function stop(string $previewUuid): void
    {
        $this->queueLifecycle($previewUuid, NodeWorkloadAction::STOP);
    }

    public function start(string $previewUuid): void
    {
        $this->queueLifecycle($previewUuid, NodeWorkloadAction::START);
    }

    public function delete(string $previewUuid): void
    {
        try {
            $preview = $this->preview($previewUuid);
            RemoveNodePreview::run($preview, auth()->user());
            $this->dispatch('success', "The preview of pull request #{$preview->pull_request_id} is being deleted.");
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    /**
     * Saves the comma-separated domains of a preview. A URL without a port keeps the container port
     * of the domain at the same position, so editing a host does not change where traffic goes.
     */
    public function saveDomains(string $previewUuid): void
    {
        $field = "previewDomains.{$previewUuid}";
        try {
            $preview = $this->preview($previewUuid);
            $current = array_values($preview->domain_routes ?? []);
            $fallbackPort = $current[0]['port'] ?? ($this->workload->health_check_port ?: 80);
            $routes = str((string) ($this->previewDomains[$previewUuid] ?? ''))
                ->explode(',')
                ->map(fn (string $url): string => trim($url))
                ->filter()
                ->values()
                ->map(function (string $url, int $index) use ($current, $fallbackPort): array {
                    $parts = DomainUrlParts::split(str_contains($url, '://') ? $url : "https://{$url}");

                    return [
                        'scheme' => $parts['scheme'],
                        'host' => $parts['host'],
                        'port' => $parts['port'] !== '' ? $parts['port'] : ($current[$index]['port'] ?? $fallbackPort),
                        'path' => $parts['path'],
                        'noindex' => (bool) ($current[$index]['noindex'] ?? false),
                    ];
                })
                ->all();
            $this->previewDomains[$previewUuid] = self::domainUrls(UpdateNodeWorkloadDomains::run($preview, $routes, auth()->user()))->implode(',');
            $this->resetErrorBag($field);
            $this->dispatch('success', 'Preview domains saved. Ingress servers apply them without a redeploy.');
        } catch (ValidationException $exception) {
            $this->addError($field, collect($exception->errors())->flatten()->first());
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function render(): View
    {
        $previews = $this->previewCards();
        // A new preview gets its field; an edited field keeps its draft across the poll.
        foreach ($previews as $preview) {
            $this->previewDomains[$preview['uuid']] ??= $preview['domains'];
        }
        $this->previewDomains = array_intersect_key($this->previewDomains, array_flip(array_column($previews, 'uuid')));

        return view('livewire.project.cluster-application.previews', [
            'previews' => $previews,
            'routeParameters' => ['project_uuid' => $this->workload->project->uuid, 'environment_uuid' => $this->workload->environment->uuid],
        ]);
    }

    private function preview(string $previewUuid, string $ability = 'update'): NodeWorkload
    {
        $this->authorize($ability, $this->workload);
        $preview = $this->workload->previews()->where('uuid', $previewUuid)->firstOrFail();
        $this->authorize($ability, $preview);

        return $preview;
    }

    private function queueLifecycle(string $previewUuid, NodeWorkloadAction $action): void
    {
        try {
            $preview = $this->preview($previewUuid, 'deploy');
            $revision = $preview->revisions()->latest('id')->first()
                ?? throw new RuntimeException('The preview has no deployment yet. Redeploy it first.');
            foreach ($preview->nodes()->orderBy('nodes.id')->get() as $node) {
                ManageNodeWorkloadJob::dispatch(CreateLifecycleOperation::run($node, $revision, $action, auth()->user())->id);
            }
            $this->dispatch('info', $action === NodeWorkloadAction::STOP ? 'Stopping the preview.' : 'Starting the preview.');
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    /**
     * Bitbucket has no ref of a pull request, so its previews deploy only from webhooks, which
     * send the source branch. GitLab names the ref after merge requests.
     */
    private function pullRequestRef(int $number): string
    {
        $host = strtolower((string) (parse_url((string) preg_replace('#\A[^@/]+@([^:/]+):#', 'ssh://$1/', (string) $this->workload->git_repository), PHP_URL_HOST) ?? ''));

        return match (true) {
            str_contains($host, 'bitbucket') => throw new RuntimeException('Bitbucket has no ref of a pull request. Its previews deploy from the pull request webhook.'),
            str_contains($host, 'gitlab') => "refs/merge-requests/{$number}/head",
            default => "refs/pull/{$number}/head",
        };
    }

    /**
     * @return list<array{uuid: string, number: int, url: ?string, fromFork: bool, removing: bool, state: ?NodeWorkloadState, status: string, statusType: string, containerPresent: bool, domains: string, firstDomain: ?string, latestBuildUuid: ?string}>
     */
    private function previewCards(): array
    {
        $previews = $this->workload->previews()
            ->with(['nodes', 'builds' => fn ($query) => $query->latest('id')->limit(1)])
            ->orderBy('pull_request_id')
            ->get();
        $withContainer = NodeContainer::query()
            ->whereIn('node_workload_id', $previews->modelKeys())
            ->where('is_managed', true)
            ->distinct()
            ->pluck('node_workload_id')
            ->all();

        return $previews->map(function (NodeWorkload $preview) use ($withContainer): array {
            $node = $preview->nodes->first();
            $state = $node === null ? null : DetermineWorkloadState::run($node, $preview);
            $urls = self::domainUrls($preview);

            return [
                'uuid' => $preview->uuid,
                'number' => (int) $preview->pull_request_id,
                'url' => $preview->pull_request_url,
                'fromFork' => (bool) $preview->pull_request_from_fork,
                'removing' => $preview->desired_state === NodeWorkloadDesiredState::REMOVED,
                'state' => $state,
                'status' => $state === null ? 'Unknown' : str($state->value)->title()->toString(),
                'statusType' => $state?->badgeType() ?? 'neutral',
                'containerPresent' => in_array($preview->id, $withContainer, true),
                'domains' => $urls->implode(','),
                'firstDomain' => $urls->first(),
                'latestBuildUuid' => $preview->builds->first()?->uuid,
            ];
        })->values()->all();
    }

    /** @return Collection<int, string> */
    private static function domainUrls(NodeWorkload $preview): Collection
    {
        return collect($preview->domain_routes ?? [])->map(fn (array $route): string => "{$route['scheme']}://{$route['host']}".($route['path'] ?? ''))->values();
    }

    private function fillSettings(): void
    {
        $this->workload->refresh();
        $this->isPreviewDeploymentsEnabled = (bool) $this->workload->preview_deployments_enabled;
        $this->isPrDeploymentsPublicEnabled = (bool) $this->workload->fork_preview_deployments_enabled;
        $this->previewUsesProductionValues = $this->workload->preview_uses_production_values !== false;
        $this->previewUrlTemplate = (string) ($this->workload->preview_url_template ?: NodeWorkload::DEFAULT_PREVIEW_URL_TEMPLATE);
    }
}

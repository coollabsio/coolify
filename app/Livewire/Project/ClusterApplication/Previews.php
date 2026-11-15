<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\DeployNodePreview;
use App\Actions\Node\DetermineWorkloadState;
use App\Actions\Node\RemoveNodePreview;
use App\Enums\NodeWorkloadDesiredState;
use App\Models\NodeBuild;
use App\Models\NodeWorkload;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * The Previews tab of a Git cluster application (decision 0025): the preview settings, the preview
 * variables, the previews of its pull requests, and Deploy pull request.
 */
class Previews extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public NodeWorkload $workload;

    public bool $previewDeploymentsEnabled = false;

    public bool $forkPreviewDeploymentsEnabled = false;

    public bool $previewUsesProductionValues = true;

    public string $previewUrlTemplate = NodeWorkload::DEFAULT_PREVIEW_URL_TEMPLATE;

    public string $pullRequestNumber = '';

    /** Why the last manual deploy has no domain. */
    public ?string $domainError = null;

    public function mount(NodeWorkload $workload): void
    {
        $this->authorize('view', $workload);
        abort_unless($workload->canHavePreviews(), 404);
        $this->workload = $workload;
        $this->fillSettings();
    }

    public function saveSettings(): void
    {
        try {
            $this->authorize('update', $this->workload);
            $this->previewUrlTemplate = str_replace(' ', '', $this->previewUrlTemplate);
            $this->validate([
                'previewDeploymentsEnabled' => ['boolean'],
                'forkPreviewDeploymentsEnabled' => ['boolean'],
                'previewUsesProductionValues' => ['boolean'],
                'previewUrlTemplate' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9.{}_-]+\z/i', 'regex:/\{\{domain\}\}/', 'regex:/\{\{(pr_id|random)\}\}/'],
            ], [
                'previewUrlTemplate.regex' => 'The template needs the domain placeholder and the pr_id or random placeholder. Use only letters, digits, dots, hyphens, and placeholders.',
            ]);
            $this->workload->update([
                'preview_deployments_enabled' => $this->previewDeploymentsEnabled,
                'fork_preview_deployments_enabled' => $this->forkPreviewDeploymentsEnabled,
                'preview_uses_production_values' => $this->previewUsesProductionValues,
                'preview_url_template' => $this->previewUrlTemplate,
            ]);
            $this->dispatch('success', 'Preview settings saved. They apply to the next preview deploy.');
        } catch (Throwable $exception) {
            $this->fillSettings();

            handleError($exception, $this);
        }
    }

    /** A manual deploy is the decision of the user, so it gets the values of a same-repository pull request, as in v4. */
    public function deployPullRequest(): void
    {
        $this->domainError = null;
        try {
            $this->authorize('update', $this->workload);
            $number = (int) $this->validate(['pullRequestNumber' => ['required', 'integer', 'min:1', 'max:2147483647']])['pullRequestNumber'];
            $ref = $this->pullRequestRef($number);
            ['build' => $build, 'domain_error' => $this->domainError] = DeployNodePreview::run($this->workload, $number, null, $ref, requestedBy: auth()->user());
            $this->pullRequestNumber = '';
            $this->dispatch('success', $build->wasRecentlyCreated ? "Preview build of pull request #{$number} queued." : "A build of pull request #{$number} is already running.");
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function removePreview(string $previewUuid): void
    {
        try {
            $preview = $this->workload->previews()->where('uuid', $previewUuid)->firstOrFail();
            RemoveNodePreview::run($preview, auth()->user());
            $this->dispatch('success', "The preview of pull request #{$preview->pull_request_id} is being removed.");
        } catch (Throwable $exception) {
            handleError($exception, $this);
        }
    }

    public function render(): View
    {
        return view('livewire.project.cluster-application.previews', [
            'previews' => $this->previewRows(),
            'routeParameters' => ['project_uuid' => $this->workload->project->uuid, 'environment_uuid' => $this->workload->environment->uuid],
        ]);
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
     * @return list<array{uuid: string, number: int, url: ?string, fromFork: bool, removing: bool, status: string, statusType: string, domains: list<string>, build: ?array{uuid: string, status: string, commit: ?string, at: ?string}}>
     */
    private function previewRows(): array
    {
        return $this->workload->previews()
            ->with(['nodes', 'builds' => fn ($query) => $query->latest('id')->limit(1)])
            ->get()
            ->map(function (NodeWorkload $preview): array {
                $node = $preview->nodes->first();
                $state = $node === null ? null : DetermineWorkloadState::run($node, $preview);
                $build = $preview->builds->first();

                return [
                    'uuid' => $preview->uuid,
                    'number' => (int) $preview->pull_request_id,
                    'url' => $preview->pull_request_url,
                    'fromFork' => (bool) $preview->pull_request_from_fork,
                    'removing' => $preview->desired_state === NodeWorkloadDesiredState::REMOVED,
                    'status' => $state === null ? 'Unknown' : str($state->value)->title()->toString(),
                    'statusType' => $state?->badgeType() ?? 'neutral',
                    'domains' => collect($preview->domain_routes ?? [])->map(fn (array $route): string => "{$route['scheme']}://{$route['host']}".($route['path'] ?? ''))->values()->all(),
                    'build' => $build instanceof NodeBuild ? [
                        'uuid' => $build->uuid,
                        'status' => str($build->status->value)->headline()->toString(),
                        'commit' => $build->commit_sha === null ? null : substr($build->commit_sha, 0, 7),
                        'at' => $build->created_at?->diffForHumans(),
                    ] : null,
                ];
            })
            ->values()
            ->all();
    }

    private function fillSettings(): void
    {
        $this->workload->refresh();
        $this->previewDeploymentsEnabled = (bool) $this->workload->preview_deployments_enabled;
        $this->forkPreviewDeploymentsEnabled = (bool) $this->workload->fork_preview_deployments_enabled;
        $this->previewUsesProductionValues = $this->workload->preview_uses_production_values !== false;
        $this->previewUrlTemplate = (string) ($this->workload->preview_url_template ?: NodeWorkload::DEFAULT_PREVIEW_URL_TEMPLATE);
    }
}

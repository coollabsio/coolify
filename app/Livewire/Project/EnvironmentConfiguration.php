<?php

namespace App\Livewire\Project;

use App\Enums\ConfigRevisionStatus;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\Project;
use App\Services\ProjectConfig\ConfigExporter;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ConfigPlan;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The coolify.json state of one environment: link, revisions, plan, approval, suspend, export.
 */
class EnvironmentConfiguration extends Component
{
    use AuthorizesRequests;

    public Project $project;

    #[Locked]
    public int $environmentId;

    /** @var list<string> keys of removed resources whose data the approval deletes */
    public array $deleteData = [];

    public ?array $resumePlan = null;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->authorize('view', $environment);
        $this->environmentId = $environment->id;
    }

    private function environment(): Environment
    {
        return Environment::query()->findOrFail($this->environmentId);
    }

    private function link(): ?EnvironmentConfigLink
    {
        return EnvironmentConfigLink::query()->where('environment_id', $this->environmentId)->first();
    }

    private function revision(string $uuid): EnvironmentConfigRevision
    {
        $link = $this->link() ?? abort(404);

        return $link->revisions()->where('uuid', $uuid)->firstOrFail();
    }

    private function authorizeTeamAdmin(EnvironmentConfigLink $link): void
    {
        abort_unless(auth()->user()->isAdminOfTeam($link->teamId()), 403, 'Only a team admin can do this.');
    }

    public function approve(string $revisionUuid, string $password = ''): mixed
    {
        try {
            $this->authorize('delete', $this->environment());
            if (! verifyPasswordConfirmation($password, $this)) {
                return 'The provided password is incorrect.';
            }
            $revision = $this->revision($revisionUuid);
            if ($revision->status !== ConfigRevisionStatus::WaitingApproval) {
                $this->dispatch('error', 'This plan does not wait for approval.');

                return false;
            }
            $removed = collect($revision->plan['changes'] ?? [])->where('kind', 'remove')->pluck('key')->all();
            $deleteData = array_values(array_intersect($this->deleteData, $removed));
            app(ConfigLinks::class)->approve($revision, auth()->user(), $deleteData);
            $this->deleteData = [];
            $this->dispatch('success', 'Plan approved. Coolify applies it now.');

            return true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function suspend(): void
    {
        try {
            $link = $this->link() ?? abort(404);
            $this->authorizeTeamAdmin($link);
            app(ConfigLinks::class)->suspend($link, auth()->user());
            $this->dispatch('success', 'Link suspended. Settings can be edited in Coolify again.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function previewResume(): void
    {
        try {
            $link = $this->link() ?? abort(404);
            $this->authorizeTeamAdmin($link);
            $plan = app(ConfigLinks::class)->resume($link, auth()->user(), false);
            $this->resumePlan = $plan instanceof ConfigPlan ? $plan->toArray() : ['changes' => []];
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function confirmResume(): void
    {
        try {
            $link = $this->link() ?? abort(404);
            $this->authorizeTeamAdmin($link);
            app(ConfigLinks::class)->resume($link, auth()->user(), true);
            $this->resumePlan = null;
            $this->dispatch('success', 'Link resumed. coolify.json manages this environment again.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function release(string $revisionUuid): void
    {
        try {
            $link = $this->link() ?? abort(404);
            $this->authorizeTeamAdmin($link);
            app(ConfigLinks::class)->release($this->revision($revisionUuid), auth()->user());
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function retry(string $revisionUuid): void
    {
        try {
            $this->authorize('update', $this->environment());
            app(ConfigLinks::class)->retry($this->revision($revisionUuid), auth()->user());
            $this->dispatch('success', 'Coolify plans the file again.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function downloadExport(): ?StreamedResponse
    {
        try {
            $environment = $this->environment();
            $this->authorize('view', $environment);
            $file = json_encode(app(ConfigExporter::class)->export($environment), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

            return response()->streamDownload(fn () => print ($file), 'coolify.json', ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return null;
        }
    }

    public function render()
    {
        $link = $this->link();
        $revisions = $link?->revisions()->latest('id')->limit(15)->get() ?? collect();
        $user = auth()->user();

        return view('livewire.project.environment-configuration', [
            'environment' => $this->environment(),
            'link' => $link,
            'revisions' => $revisions,
            'waiting' => $revisions->firstWhere('status', ConfigRevisionStatus::WaitingApproval),
            'latest' => $revisions->first(),
            'isTeamAdmin' => $link !== null && $user->isAdminOfTeam($link->teamId()),
            'canApprove' => $user->can('delete', $this->environment()),
        ]);
    }
}

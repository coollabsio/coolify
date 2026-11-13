<?php

namespace App\Livewire\Project;

use App\Enums\ConfigRevisionStatus;
use App\Http\Controllers\Api\ProjectConfigController;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Rules\ValidGitBranch;
use App\Services\ProjectConfig\ConfigExporter;
use App\Services\ProjectConfig\ConfigLinkPreview;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ConfigPlan;
use App\Services\ProjectConfig\ConfigSyncStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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

    public bool $autoApply = true;

    public string $connectSourceType = 'public';

    public ?string $connectSourceUuid = null;

    public string $connectRepository = '';

    public string $connectBranch = 'main';

    public string $connectFilePath = 'coolify.json';

    public ?string $connectServerUuid = null;

    #[Locked]
    public ?array $connectPlan = null;

    #[Locked]
    public ?string $connectError = null;

    public function mount(string $project_uuid, string $environment_uuid): void
    {
        $this->project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $this->project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->authorize('view', $environment);
        $this->environmentId = $environment->id;
        $this->autoApply = (bool) ($this->link()?->auto_apply ?? true);
    }

    public function saveAutoApply(): void
    {
        $this->setAutoApply($this->autoApply);
        $this->autoApply = (bool) ($this->link()?->auto_apply ?? true);
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

    /**
     * @return array{project: string, environment: string, mode: string, repository: string, branch: string, file_path: string, source_type: string, source_uuid: ?string, read_server_uuid: ?string}
     */
    private function connectData(): array
    {
        $environment = $this->environment();
        $data = Validator::make([
            'repository' => trim($this->connectRepository),
            'branch' => trim($this->connectBranch),
            'file_path' => ltrim(trim($this->connectFilePath), '/') ?: 'coolify.json',
            'source_type' => $this->connectSourceType,
            'source_uuid' => $this->connectSourceType === 'public' ? null : $this->connectSourceUuid,
            'read_server_uuid' => $this->connectServerUuid,
        ], [
            'repository' => 'required|string|max:500',
            'branch' => ['required', 'string', new ValidGitBranch],
            'file_path' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+\z#', 'not_regex:#(^|/)\.\.(/|\z)#'],
            'source_type' => 'required|in:public,github_app,gitea_app,deploy_key',
            'source_uuid' => 'required_unless:source_type,public|nullable|string',
            'read_server_uuid' => 'required|string',
        ])->validate();

        return $data + ['project' => $environment->project->name, 'environment' => $environment->name, 'mode' => EnvironmentConfigLink::MODE_GIT];
    }

    public function previewConnect(): void
    {
        $this->connectPlan = null;
        $this->connectError = null;
        try {
            $environment = $this->environment();
            $teamId = (int) $environment->project->team_id;
            $this->authorizeTeamAdminOf($teamId);
            $link = new EnvironmentConfigLink(['environment_id' => $environment->id, 'mode' => EnvironmentConfigLink::MODE_GIT]);
            $link->forceFill(app(ConfigLinks::class)->gitAttributes(auth()->user(), $teamId, $this->connectData()));
            $link->setRelation('environment', $environment);
            ['plan' => $this->connectPlan, 'error' => $this->connectError] = app(ConfigLinkPreview::class)->plan($link, auth()->user());
        } catch (ValidationException $e) {
            $this->connectError = collect($e->errors())->flatten()->first();
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function connect(): void
    {
        try {
            $environment = $this->environment();
            $teamId = (int) $environment->project->team_id;
            $this->authorizeTeamAdminOf($teamId);
            $link = app(ConfigLinks::class)->create(auth()->user(), $teamId, $this->connectData());
            PlanEnvironmentConfigJob::dispatch($link, 'link');
            $this->reset('connectPlan', 'connectError');
            $this->dispatch('close-modal');
            $this->dispatch('success', 'Connected. Coolify reads the file now.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function disconnect(): void
    {
        try {
            $link = $this->link() ?? abort(404);
            app(ConfigLinks::class)->disconnect($link, auth()->user());
            $this->dispatch('success', 'Disconnected. The resources stay and can be edited in Coolify.');
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function setAutoApply(bool $autoApply): void
    {
        try {
            $link = $this->link() ?? abort(404);
            app(ConfigLinks::class)->setAutoApply($link, auth()->user(), $autoApply);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    private function authorizeTeamAdminOf(int $teamId): void
    {
        abort_unless(auth()->user()->isAdminOfTeam($teamId), 403, 'Only a team admin can do this.');
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
            // Commands and Compose files can hold credentials.
            $this->authorizeTeamAdminOf((int) $environment->project->team_id);
            $file = json_encode(app(ConfigExporter::class)->export($environment), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

            return response()->streamDownload(fn () => print ($file), 'coolify.json', ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            handleError($e, $this);

            return null;
        }
    }

    public function render()
    {
        $environment = $this->environment();
        $link = $this->link();
        $revisions = $link?->revisions()->latest('id')->limit(20)->get() ?? collect();
        $user = auth()->user();
        $teamId = (int) $environment->project->team_id;
        $isTeamAdmin = $user->isAdminOfTeam($teamId);

        return view('livewire.project.environment-configuration', [
            'environment' => $environment,
            'link' => $link,
            'status' => $link ? ConfigSyncStatus::forLink($link) : null,
            'revisions' => $revisions,
            'waiting' => $revisions->firstWhere('status', ConfigRevisionStatus::WaitingApproval),
            'latest' => $revisions->first(fn ($revision) => ! in_array($revision->status, [ConfigRevisionStatus::Skipped, ConfigRevisionStatus::Superseded], true)),
            'isTeamAdmin' => $isTeamAdmin,
            'canApprove' => $user->can('delete', $environment),
            'webhook' => $link?->isGit() && $isTeamAdmin ? ProjectConfigController::webhookDetails($link) : null,
            'connectServers' => $isTeamAdmin && ! $link ? $this->connectServers($teamId) : [],
            'connectSources' => $isTeamAdmin && ! $link ? $this->connectSources($teamId) : [],
        ]);
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    private function connectServers(int $teamId): array
    {
        return Server::query()->where('team_id', $teamId)->orderBy('name')->get()
            ->filter(fn (Server $server) => ! $server->isTransferredAway() && Gate::allows('placeResource', $server))
            ->map(fn (Server $server) => ['uuid' => $server->uuid, 'name' => $server->name])
            ->values()->all();
    }

    /**
     * @return array<string, list<array{uuid: string, name: string}>>
     */
    private function connectSources(int $teamId): array
    {
        $teamScope = fn ($query) => $query->where(fn ($inner) => $inner->where('team_id', $teamId)->orWhere('is_system_wide', true));

        return [
            'github_app' => GithubApp::query()->where('is_public', false)->tap($teamScope)->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'gitea_app' => GiteaApp::query()->tap($teamScope)->orderBy('name')->get(['uuid', 'name'])->toArray(),
            'deploy_key' => PrivateKey::query()->where('team_id', $teamId)->orderBy('name')->get(['uuid', 'name'])->toArray(),
        ];
    }
}

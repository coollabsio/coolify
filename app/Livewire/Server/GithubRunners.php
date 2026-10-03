<?php

namespace App\Livewire\Server;

use App\Actions\Server\InstallSysbox;
use App\Enums\GithubRunnerDockerMode;
use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\Server;
use App\Rules\DockerImageFormat;
use App\Services\GithubRunner\GithubRunnerApi;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GithubRunners extends Component
{
    use AuthorizesRequests;

    /** Labels that GitHub or Coolify already set, so they cannot select Coolify runners alone. */
    private const RESERVED_LABELS = ['self-hosted', 'linux', 'x64', 'arm64', 'arm', 'windows', 'macos'];

    public Server $server;

    public array $parameters = [];

    public ?int $githubAppId = null;

    public string $labels = 'coolify';

    public int $maxRunners = 2;

    public string $dockerMode = 'none';

    public ?string $runnerImage = null;

    public ?string $cpuLimit = null;

    public ?string $memoryLimit = null;

    public int $capacityWaitTimeout = 60;

    public int $idleTimeout = 10;

    public int $jobTimeout = 360;

    public bool $isDedicated = false;

    public bool $allowPullRequests = false;

    /** Null until checked over SSH. */
    public ?bool $isSysboxInstalled = null;

    protected $listeners = ['sysboxInstalled' => 'checkSysbox'];

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->authorize('view', $this->server);
            if ($this->server->isLocalhost()) {
                return redirect()->route('server.show', ['server_uuid' => $this->server->uuid]);
            }
            $this->parameters = get_route_parameters();
            $this->syncData();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    protected function rules(): array
    {
        return [
            'githubAppId' => ['required', 'integer', Rule::in($this->githubApps->pluck('id')->all())],
            'labels' => ['required', 'string', 'max:1000'],
            'maxRunners' => ['required', 'integer', 'min:1', 'max:32'],
            'dockerMode' => ['required', Rule::enum(GithubRunnerDockerMode::class)],
            'runnerImage' => ['nullable', 'string', 'max:255', new DockerImageFormat],
            'cpuLimit' => ['nullable', 'string', 'regex:/\A\d+(\.\d+)?\z/'],
            'memoryLimit' => ['nullable', 'string', 'regex:/\A\d+[bkmg]?\z/i'],
            'capacityWaitTimeout' => ['required', 'integer', 'min:1', 'max:1440'],
            'idleTimeout' => ['required', 'integer', 'min:1', 'max:1440'],
            'jobTimeout' => ['required', 'integer', 'min:1', 'max:7200'],
            'isDedicated' => ['required', 'boolean'],
            'allowPullRequests' => ['required', 'boolean'],
        ];
    }

    /**
     * Organization GitHub Apps of the server's team. System-wide Apps of other teams are not allowed.
     */
    #[Computed]
    public function githubApps(): Collection
    {
        return GithubApp::query()
            ->where('team_id', $this->server->team_id)
            ->whereNotNull('app_id')
            ->whereNotNull('installation_id')
            ->whereNotNull('organization')
            ->where('organization', '!=', '')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function selectedApp(): ?GithubApp
    {
        return $this->githubApps->firstWhere('id', $this->githubAppId);
    }

    #[Computed]
    public function config(): ?GithubRunnerConfig
    {
        return $this->server->githubRunnerConfig()->first();
    }

    private function syncData(): void
    {
        $config = $this->config;
        if (! $config) {
            $apps = $this->githubApps;
            $this->githubAppId = ($apps->first(fn (GithubApp $app) => $app->missingRunnerRequirements() === []) ?? $apps->first())?->id;

            return;
        }

        $this->githubAppId = $config->github_app_id;
        $this->labels = implode(', ', $config->labels ?? []);
        $this->maxRunners = $config->max_runners;
        $this->dockerMode = $config->docker_mode->value;
        $this->runnerImage = $config->runner_image;
        $this->cpuLimit = $config->cpu_limit;
        $this->memoryLimit = $config->memory_limit;
        $this->capacityWaitTimeout = $config->capacity_wait_timeout;
        $this->idleTimeout = $config->idle_timeout;
        $this->jobTimeout = $config->job_timeout;
        $this->isDedicated = $config->is_dedicated;
        $this->allowPullRequests = $config->allow_pull_requests;
    }

    /**
     * @return array<int, string>
     */
    private function parseLabels(): array
    {
        $labels = collect(explode(',', $this->labels))
            ->map(fn (string $label) => strtolower(trim($label)))
            ->filter()
            ->unique()
            ->values();

        foreach ($labels as $label) {
            if (! preg_match('/\A[a-z0-9][a-z0-9._-]{0,99}\z/', $label)) {
                throw ValidationException::withMessages(['labels' => "The label \"{$label}\" can only contain letters, numbers, dots, dashes, and underscores."]);
            }
        }

        $custom = $labels->reject(fn (string $label) => in_array($label, self::RESERVED_LABELS, true))->values();
        if ($custom->isEmpty()) {
            throw ValidationException::withMessages(['labels' => 'Add at least one custom label, for example "coolify". Coolify only takes jobs that ask for a custom label.']);
        }

        return $custom->all();
    }

    public function submit()
    {
        $this->authorize('update', $this->server);
        $this->validate();
        $labels = $this->parseLabels();
        $githubApp = $this->selectedApp;
        $this->authorize('update', $githubApp);
        if ($this->dockerMode === GithubRunnerDockerMode::Sysbox->value && ! InstallSysbox::isInstalled($this->server)) {
            $this->isSysboxInstalled = false;
            throw ValidationException::withMessages(['dockerMode' => 'Sysbox is not installed on this server. Install it, then save again.']);
        }

        try {
            if (! $this->server->isBuildServer()) {
                throw new \RuntimeException('GitHub Actions runners can only run on servers with the Build role.');
            }

            if ($this->config?->is_enabled ?? true) {
                $this->prepareRunnerGroup($githubApp);
            }

            GithubRunnerConfig::updateOrCreate(['server_id' => $this->server->id], [
                'github_app_id' => $githubApp->id,
                'labels' => $labels,
                'max_runners' => $this->maxRunners,
                'docker_mode' => $this->dockerMode,
                'runner_image' => filled($this->runnerImage) ? trim($this->runnerImage) : null,
                'cpu_limit' => filled($this->cpuLimit) ? $this->cpuLimit : null,
                'memory_limit' => filled($this->memoryLimit) ? strtolower($this->memoryLimit) : null,
                'capacity_wait_timeout' => $this->capacityWaitTimeout,
                'idle_timeout' => $this->idleTimeout,
                'job_timeout' => $this->jobTimeout,
                'is_dedicated' => $this->isDedicated,
                'allow_pull_requests' => $this->allowPullRequests,
            ]);
            unset($this->config);
            $this->syncData();
            $this->dispatch('success', 'GitHub runner settings saved.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Starts or stops taking workflow jobs on this server. The first enable saves the default settings.
     * Disabling removes the idle runners so that GitHub cannot give them new jobs. Running jobs continue
     * until they finish.
     */
    public function toggleEnabled()
    {
        $this->authorize('update', $this->server);

        try {
            $config = $this->config;
            if (! $config) {
                return $this->submit();
            }

            if ($config->is_enabled) {
                $config->update(['is_enabled' => false]);
                $config->executions()->where('status', GithubRunnerStatus::Idle)->get()
                    ->each(function (GithubRunnerExecution $execution) {
                        $execution->finish(GithubRunnerStatus::Cancelled, 'Runners were disabled on this server.');
                        CleanupGithubRunnerJob::dispatch($execution->id);
                    });
                $this->dispatch('success', 'GitHub runners disabled. Running jobs continue until they finish.');
            } else {
                if (! $this->server->isBuildServer()) {
                    throw new \RuntimeException('GitHub Actions runners can only run on servers with the Build role.');
                }
                $this->authorize('update', $config->githubApp);
                $this->prepareRunnerGroup($config->githubApp);
                $config->update(['is_enabled' => true]);
                ProvisionGithubRunnerJob::dispatchQueued($config->github_app_id);
                $this->dispatch('success', 'GitHub runners enabled.');
            }
            unset($this->config);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function checkSysbox(): void
    {
        $this->authorize('view', $this->server);
        $this->isSysboxInstalled = InstallSysbox::isInstalled($this->server);
    }

    public function installSysbox()
    {
        $this->authorize('update', $this->server);

        try {
            $activity = InstallSysbox::run($this->server);
            $this->dispatch('sysbox-install-started');
            $this->dispatch('activityMonitor', $activity->id, 'sysboxInstalled');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Creates the runner group when the App can run workflow jobs. An App that is not ready is saved anyway:
     * the page lists what it misses, and the runner group is created when the first job is provisioned.
     */
    private function prepareRunnerGroup(GithubApp $githubApp): void
    {
        if ($githubApp->missingRunnerRequirements() !== []) {
            $this->dispatch('warning', 'The GitHub App is not ready for runners. Coolify cannot take workflow jobs until you update its permissions on GitHub.');

            return;
        }

        $group = (new GithubRunnerApi($githubApp))->ensureRunnerGroup();
        if ($group['is_default']) {
            $this->dispatch('warning', 'Runners use the Default runner group of the organization. Check which repositories can use that group on GitHub.');
        }
    }

    public function render()
    {
        return view('livewire.server.github-runners');
    }
}

<?php

namespace App\Livewire\Railway;

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Support\RailwayResourceMapper;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Railway service detail slide-over. Opens from a canvas node click and
 * surfaces Deployments / Variables / Metrics / Console / Settings for the
 * selected resource, wired to the existing Coolify data.
 */
class ServicePanel extends Component
{
    public Project $project;

    public Environment $environment;

    public bool $open = false;

    public string $tab = 'deployments';

    public ?string $selectedUuid = null;

    public ?string $kind = null;

    public ?string $name = null;

    public ?string $subtitle = null;

    public ?string $status = null;

    public string $icon = 'git';

    /** The resolved Eloquent resource (application/service/database) for inline embeds. */
    public $resource = null;

    /** @var array<int, string> */
    public array $fqdns = [];

    /** @var array<int, array<string, mixed>> */
    public array $deployments = [];

    public ?int $activeDeploymentId = null;

    /** @var array<int, string> */
    public array $variableKeys = [];

    /** @var array<string, string> */
    public array $links = [];

    /** @var array<string, mixed>|null Currently-open deployment detail (logs popup). */
    public ?array $deploymentDetail = null;

    public string $deploymentLogTab = 'deploy';

    public string $settingsSection = 'source';

    public string $settingsName = '';

    public ?string $settingsDescription = null;

    public ?string $settingsDomains = null;

    public ?string $settingsRepo = null;

    public ?string $settingsBranch = null;

    public ?string $settingsBaseDirectory = null;

    public ?string $settingsInstallCommand = null;

    public ?string $settingsBuildCommand = null;

    public ?string $settingsStartCommand = null;

    public bool $settingsAutoDeploy = false;

    public bool $isApplication = false;

    public function mount(Environment $environment, Project $project): void
    {
        $this->environment = $environment;
        $this->project = $project;
    }

    #[On('openServicePanel')]
    public function openPanel(string $uuid, string $kind, ?string $name = null): void
    {
        $this->reset(['deployments', 'variableKeys', 'fqdns', 'links', 'activeDeploymentId']);
        $this->selectedUuid = $uuid;
        $this->kind = $kind;
        $this->name = $name;
        $this->tab = 'deployments';
        $this->open = true;

        $this->loadResource();
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
    }

    /**
     * Open the per-deployment logs popup (Railway-style: Details / Build / Deploy / HTTP / Network Flow).
     * Coolify persists each deployment's logs on ApplicationDeploymentQueue, so they survive.
     */
    public function openDeploymentLogs(int $id): void
    {
        $this->deploymentLogTab = 'deploy';
        $this->deploymentDetail = null;

        if (! ($this->resource instanceof Application)) {
            return;
        }

        $deployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->resource->id)
            ->where('id', $id)
            ->first();

        if (! $deployment) {
            return;
        }

        $lines = [];
        try {
            $decoded = is_string($deployment->logs) ? json_decode($deployment->logs, true) : (is_array($deployment->logs) ? $deployment->logs : []);
            if (is_array($decoded)) {
                $lines = collect($decoded)
                    ->reject(fn ($e) => (bool) data_get($e, 'hidden', false))
                    ->map(fn ($e) => [
                        'ts' => data_get($e, 'timestamp'),
                        'line' => (string) data_get($e, 'output', ''),
                        'type' => (string) data_get($e, 'type', 'stdout'),
                    ])
                    ->values()
                    ->all();
            }
        } catch (\Throwable $e) {
            $lines = [];
        }

        $this->deploymentDetail = [
            'id' => $deployment->id,
            'uuid' => $deployment->deployment_uuid,
            'commit' => $deployment->commit ? substr($deployment->commit, 0, 7) : null,
            'message' => $deployment->commit_message ?: 'Deployment',
            'status' => (string) $deployment->status,
            'created_at' => optional($deployment->created_at)->format('M j, Y, g:i A'),
            'finished_at' => optional($deployment->finished_at)->format('M j, Y, g:i A'),
            'server' => $deployment->server_name,
            'via' => $deployment->git_type ? 'via '.ucfirst((string) $deployment->git_type) : ($deployment->is_webhook ? 'via Webhook' : 'via Coolify'),
            'lines' => $lines,
        ];
    }

    public function closeDeploymentLogs(): void
    {
        $this->deploymentDetail = null;
    }

    public function setDeploymentLogTab(string $tab): void
    {
        $this->deploymentLogTab = $tab;
    }

    protected function resolveResource(): ?Model
    {
        return match ($this->kind) {
            'application' => $this->environment->applications()->where('uuid', $this->selectedUuid)->first(),
            'service' => $this->environment->services()->where('uuid', $this->selectedUuid)->first(),
            'database' => $this->environment->databases()->firstWhere('uuid', $this->selectedUuid),
            default => null,
        };
    }

    protected function loadResource(): void
    {
        $resource = $this->resolveResource();
        if (! $resource) {
            return;
        }

        $this->resource = $resource;
        $this->name = $resource->name;
        $this->status = (string) ($resource->status ?? '');
        $this->icon = RailwayResourceMapper::iconType($resource);
        $this->subtitle = RailwayResourceMapper::subtitle($resource);

        if (filled($resource->fqdn ?? null)) {
            $this->fqdns = collect(explode(',', (string) $resource->fqdn))
                ->map(fn ($f) => trim($f))
                ->filter()
                ->values()
                ->all();
        }

        $this->loadDeployments($resource);
        $this->loadVariables($resource);
        $this->loadSettingsFields($resource);
        $this->buildLinks();
    }

    protected function loadSettingsFields(Model $resource): void
    {
        $this->isApplication = $resource instanceof Application;
        $this->settingsSection = $this->isApplication ? 'source' : 'general';
        $this->settingsName = (string) $resource->name;
        $this->settingsDescription = $resource->description;
        $this->settingsDomains = $resource->fqdn ?? null;

        if ($resource instanceof Application) {
            $this->settingsRepo = $resource->git_repository;
            $this->settingsBranch = $resource->git_branch;
            $this->settingsBaseDirectory = $resource->base_directory;
            $this->settingsInstallCommand = $resource->install_command;
            $this->settingsBuildCommand = $resource->build_command;
            $this->settingsStartCommand = $resource->start_command;
            $this->settingsAutoDeploy = (bool) data_get($resource, 'settings.is_auto_deploy_enabled', false);
        }
    }

    public function setSettingsSection(string $section): void
    {
        $this->settingsSection = $section;
    }

    public function saveSettings(): void
    {
        if (! $this->resource) {
            return;
        }

        $this->validate([
            'settingsName' => 'required|string|min:1|max:255',
            'settingsDescription' => 'nullable|string|max:255',
        ]);

        try {
            $this->authorize('update', $this->resource);

            $data = [
                'name' => $this->settingsName,
                'description' => $this->settingsDescription,
            ];
            if ($this->resource instanceof Application || array_key_exists('fqdn', $this->resource->getAttributes())) {
                $data['fqdn'] = filled($this->settingsDomains) ? $this->settingsDomains : null;
            }
            if ($this->resource instanceof Application) {
                $data['git_branch'] = $this->settingsBranch;
                $data['base_directory'] = $this->settingsBaseDirectory;
                $data['install_command'] = $this->settingsInstallCommand;
                $data['build_command'] = $this->settingsBuildCommand;
                $data['start_command'] = $this->settingsStartCommand;
            }

            $this->resource->update($data);

            $this->name = $this->settingsName;
            $this->fqdns = filled($this->settingsDomains)
                ? collect(explode(',', $this->settingsDomains))->map(fn ($f) => trim($f))->filter()->values()->all()
                : [];

            $this->dispatch('success', 'Settings saved.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Could not save settings.');
        }
    }

    public function toggleAutoDeploy(): void
    {
        if (! ($this->resource instanceof Application)) {
            return;
        }

        try {
            $this->authorize('update', $this->resource);
            $settings = $this->resource->settings;
            $settings->is_auto_deploy_enabled = ! $settings->is_auto_deploy_enabled;
            $settings->save();
            $this->settingsAutoDeploy = (bool) $settings->is_auto_deploy_enabled;
            $this->dispatch('success', $this->settingsAutoDeploy ? 'Auto-deploy enabled.' : 'Auto-deploy disabled.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Could not update auto-deploy.');
        }
    }

    /**
     * Delete the resource (containers + volumes) using Coolify's real DeleteResourceJob,
     * then close the panel and refresh the canvas.
     */
    public function deleteResource(): void
    {
        if (! $this->resource) {
            return;
        }

        try {
            $this->authorize('delete', $this->resource);
            $resource = $this->resource;
            $resource->delete();
            DeleteResourceJob::dispatch($resource, true, true, true, false);

            $this->open = false;
            $this->dispatch('success', 'Resource deletion started.');
            $this->dispatch('refreshCanvas');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Could not delete resource.');
        }
    }

    protected function loadDeployments(Model $resource): void
    {
        if (! ($resource instanceof Application)) {
            return;
        }

        try {
            $result = $resource->deployments(0, 12);
            $deployments = $result['deployments'] ?? collect();

            $this->deployments = $deployments->map(fn ($d) => [
                'id' => $d->id,
                'status' => (string) $d->status,
                'commit' => $d->commit ? substr($d->commit, 0, 7) : null,
                'message' => $d->commit_message ?: 'Manual deployment',
                'via' => $d->git_type ? 'via '.ucfirst((string) $d->git_type) : ($d->is_webhook ? 'via Webhook' : ($d->is_api ? 'via API' : 'via Coolify')),
                'ago' => optional($d->created_at)->diffForHumans(),
            ])->all();

            $this->activeDeploymentId = optional($resource->get_last_successful_deployment())->id;
        } catch (\Throwable $e) {
            $this->deployments = [];
        }
    }

    protected function loadVariables(Model $resource): void
    {
        try {
            if (! method_exists($resource, 'environment_variables')) {
                return;
            }
            $this->variableKeys = $resource->environment_variables()
                ->orderBy('key')
                ->pluck('key')
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->variableKeys = [];
        }
    }

    protected function buildLinks(): void
    {
        $base = [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
        ];

        try {
            if ($this->kind === 'application') {
                $p = $base + ['application_uuid' => $this->selectedUuid];
                $this->links = [
                    'configuration' => route('project.application.configuration', $p),
                    'logs' => route('project.application.logs', $p),
                    'terminal' => route('project.application.command', $p),
                    'metrics' => route('project.application.metrics', $p),
                    'deployments' => route('project.application.deployment.index', $p),
                ];
            } elseif ($this->kind === 'service') {
                $p = $base + ['service_uuid' => $this->selectedUuid];
                $this->links = [
                    'configuration' => route('project.service.configuration', $p),
                ];
            } else {
                $p = $base + ['database_uuid' => $this->selectedUuid];
                $this->links = [
                    'configuration' => route('project.database.configuration', $p),
                    'logs' => route('project.database.logs', $p),
                    'terminal' => route('project.database.command', $p),
                    'metrics' => route('project.database.metrics', $p),
                ];
            }
        } catch (\Throwable $e) {
            $this->links = [];
        }
    }

    public function render()
    {
        return view('livewire.railway.service-panel');
    }
}

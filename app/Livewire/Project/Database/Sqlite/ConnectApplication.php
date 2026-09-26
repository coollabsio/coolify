<?php

namespace App\Livewire\Project\Database\Sqlite;

use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\StandaloneSqlite;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Component;

class ConnectApplication extends Component
{
    use AuthorizesRequests;

    public StandaloneSqlite $database;

    public string $applicationUuid = '';

    public string $mountPath = StandaloneSqlite::DATA_DIRECTORY;

    /**
     * Docker volume that holds the database files.
     */
    public string $volumeName = '';

    /**
     * Applications on the same server. Compose applications are listed but disabled,
     * because Coolify renames named volumes in compose files.
     *
     * @var array<int, array{value: string, label: string, disabled: bool}>
     */
    public array $applicationOptions = [];

    public function mount(): void
    {
        $this->authorize('view', $this->database);

        $this->volumeName = $this->database->persistentStorages()
            ->whereNull('host_path')
            ->orderBy('id')
            ->value('name') ?? 'sqlite-data-'.$this->database->uuid;

        $this->applicationOptions = $this->connectableApplications()
            ->map(fn (Application $application) => [
                'value' => $application->uuid,
                'label' => $this->applicationLabel($application),
                'disabled' => $this->isComposeApplication($application),
            ])
            ->all();
    }

    /**
     * Mount the data volume into the selected application and open its storage page.
     */
    public function connect()
    {
        $this->authorize('view', $this->database);

        $this->validate([
            'applicationUuid' => 'required|string',
            'mountPath' => ['required', 'string', 'regex:'.ValidationPatterns::DIRECTORY_PATH_PATTERN],
        ], [
            'applicationUuid.required' => 'Select an application first.',
            'mountPath.regex' => 'Mount path must start with / and only contain safe path characters.',
        ]);

        $application = $this->selectedApplication();
        if ($application) {
            $this->authorize('update', $application);
        }

        try {
            if (! $application) {
                throw new \Exception('The selected application is not available on this server.');
            }

            if ($this->isComposeApplication($application)) {
                throw new \Exception('Docker Compose applications are not supported: Coolify renames named volumes in compose files.');
            }

            if ($application->persistentStorages()->where('standalone_sqlite_id', $this->database->id)->exists()) {
                throw new \Exception("{$application->name} already mounts this database volume.");
            }

            LocalPersistentVolume::create([
                'name' => $this->volumeName,
                'mount_path' => $this->mountPath,
                'host_path' => null,
                'standalone_sqlite_id' => $this->database->id,
                'resource_id' => $application->id,
                'resource_type' => $application->getMorphClass(),
                'is_preview_suffix_enabled' => false,
            ]);

            return redirect()->route('project.application.persistent-storage', $this->applicationRouteParameters($application));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.database.sqlite.connect-application');
    }

    /**
     * Applications owned by the current team that run on the same server as the database.
     *
     * @return Collection<int, Application>
     */
    private function connectableApplications(): Collection
    {
        $serverId = $this->database->destination?->server_id;
        if ($serverId === null) {
            return collect();
        }

        return Application::ownedByCurrentTeam()
            ->with(['destination', 'environment.project'])
            ->get()
            ->filter(fn (Application $application) => $application->destination?->server_id === $serverId)
            ->values();
    }

    private function selectedApplication(): ?Application
    {
        if (blank($this->applicationUuid)) {
            return null;
        }

        return $this->connectableApplications()->firstWhere('uuid', $this->applicationUuid);
    }

    private function applicationLabel(Application $application): string
    {
        $label = $application->name;

        $project = $application->environment?->project?->name;
        $environment = $application->environment?->name;
        if ($project && $environment) {
            $label .= " ({$project} / {$environment})";
        }

        if ($this->isComposeApplication($application)) {
            $label .= ' · Docker Compose (not supported)';
        }

        return $label;
    }

    private function isComposeApplication(Application $application): bool
    {
        return $application->build_pack === 'dockercompose';
    }

    /**
     * @return array{project_uuid: string, environment_uuid: string, application_uuid: string}
     */
    private function applicationRouteParameters(Application $application): array
    {
        return [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ];
    }
}

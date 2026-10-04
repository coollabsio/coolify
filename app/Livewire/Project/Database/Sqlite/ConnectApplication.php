<?php

namespace App\Livewire\Project\Database\Sqlite;

use App\Livewire\Concerns\AuditsStorageChanges;
use App\Models\Application;
use App\Models\LocalPersistentVolume;
use App\Models\StandaloneSqlite;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\Yaml\Yaml;

class ConnectApplication extends Component
{
    use AuditsStorageChanges;
    use AuthorizesRequests;

    public StandaloneSqlite $database;

    public string $applicationUuid = '';

    public string $mountPath = StandaloneSqlite::DATA_DIRECTORY;

    /**
     * Docker volume that holds the database files.
     */
    public string $volumeName = '';

    /**
     * Applications on the same server.
     *
     * @var array<int, array{value: string, label: string}>
     */
    public array $applicationOptions = [];

    /**
     * Compose file lines that mount the data volume into the selected Docker Compose application.
     */
    public ?string $composeSnippet = null;

    public ?string $composeApplicationName = null;

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
            ])
            ->all();
    }

    /**
     * Mount the data volume into the selected application and open its storage page. A Docker Compose
     * application mounts volumes only from its compose file, so it gets the lines to add instead.
     */
    public function connect()
    {
        $this->authorize('view', $this->database);
        $this->composeSnippet = null;
        $this->composeApplicationName = null;

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
                if (in_array($this->volumeName, composeExternalVolumeDockerNames($application->docker_compose_raw), true)) {
                    throw new \Exception("{$application->name} already mounts this database volume in its compose file.");
                }

                $this->composeApplicationName = $application->name;
                $this->composeSnippet = $this->composeVolumeSnippet($application);

                return null;
            }

            if ($reason = $application->persistentStorageUnavailableReason()) {
                throw new \Exception($reason);
            }

            if ($application->persistentStorages()->where('standalone_sqlite_id', $this->database->id)->exists()) {
                throw new \Exception("{$application->name} already mounts this database volume.");
            }

            $volume = LocalPersistentVolume::create([
                'name' => $this->volumeName,
                'mount_path' => $this->mountPath,
                'host_path' => null,
                'standalone_sqlite_id' => $this->database->id,
                'resource_id' => $application->id,
                'resource_type' => $application->getMorphClass(),
                'is_preview_suffix_enabled' => false,
            ]);
            $this->auditStorageChange($application, 'created', $volume, $this->sqliteAuditContext());

            return redirect()->route('project.application.persistent-storage', $this->applicationRouteParameters($application));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Volumes of applications that mount this database's data volume.
     *
     * @return Collection<int, LocalPersistentVolume>
     */
    public function getConnectionsProperty(): Collection
    {
        return $this->database->connectedVolumes()->with('resource')->orderBy('id')->get();
    }

    /**
     * Docker Compose applications that declare the data volume as external in their compose file.
     *
     * @return Collection<int, Application>
     */
    #[Computed]
    public function composeConnections(): Collection
    {
        return $this->database->composeApplicationsUsingDataVolume($this->volumeName);
    }

    /**
     * Remove the mount from the application. The Docker volume and its data stay untouched.
     */
    public function unlink(int $volumeId): void
    {
        $this->authorize('view', $this->database);

        $volume = $this->database->connectedVolumes()->with('resource')->findOrFail($volumeId);
        $this->authorize('update', $volume->resource ?? $this->database);

        $volume->delete();
        if ($volume->resource !== null) {
            $this->auditStorageChange($volume->resource, 'deleted', $volume, $this->sqliteAuditContext());
        }

        $this->dispatch('success', 'Application unlinked. Redeploy it to apply the change.');
    }

    public function render()
    {
        return view('livewire.project.database.sqlite.connect-application');
    }

    /**
     * @return array{standalone_sqlite_uuid: string, standalone_sqlite_name: string}
     */
    private function sqliteAuditContext(): array
    {
        return [
            'standalone_sqlite_uuid' => $this->database->uuid,
            'standalone_sqlite_name' => $this->database->name,
        ];
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
            $label .= ' · Docker Compose';
        }

        return $label;
    }

    private function isComposeApplication(Application $application): bool
    {
        return $application->build_pack === 'dockercompose';
    }

    /**
     * Coolify uses an external volume as written, so the application mounts the existing data volume.
     */
    private function composeVolumeSnippet(Application $application): string
    {
        try {
            $services = data_get(Yaml::parse((string) $application->docker_compose_raw), 'services');
        } catch (\Throwable) {
            $services = null;
        }
        $serviceName = is_array($services) && $services !== [] ? (string) array_key_first($services) : 'app';

        return Yaml::dump([
            'services' => [$serviceName => ['volumes' => ["{$this->volumeName}:{$this->mountPath}"]]],
            'volumes' => [$this->volumeName => ['external' => true]],
        ], 4, 2);
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

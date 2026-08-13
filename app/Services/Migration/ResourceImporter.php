<?php

namespace App\Services\Migration;

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ResourceImporter
{
    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $uuidMap
     */
    public function import(
        array $resource,
        StandaloneDocker|SwarmDocker $destination,
        Environment $environment,
        array &$uuidMap,
    ): Model {
        $type = (string) ($resource['type'] ?? '');

        $created = match (true) {
            $type === 'application' => $this->importApplication($resource, $destination, $environment, $uuidMap),
            $type === 'service' => $this->importService($resource, $destination, $environment, $uuidMap),
            str_starts_with($type, 'standalone-') => $this->importDatabase($resource, $destination, $environment, $uuidMap),
            default => throw new RuntimeException("Unsupported resource type [{$type}]."),
        };

        $uuidMap[(string) $resource['source_uuid']] = $created->uuid;

        return $created;
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $uuidMap
     */
    private function importApplication(
        array $resource,
        StandaloneDocker|SwarmDocker $destination,
        Environment $environment,
        array $uuidMap,
    ): Application {
        $uuid = new_public_id();
        $application = new Application;
        $attributes = $this->attributes($resource, $application);
        $server = $destination->server;
        $application->fill($attributes);
        $application->uuid = $uuid;
        $application->name = $resource['name'] ?? ('migrated-'.$uuid);
        $application->status = 'exited';
        $application->environment_id = $environment->id;
        $application->destination_id = $destination->id;
        $application->destination_type = $destination->getMorphClass();
        $this->applyGitSource($application, $resource, $environment->project->team_id);

        try {
            if ($server->proxyType() !== 'NONE') {
                $application->fqdn = generateUrl(server: $server, random: $uuid);
            }
        } catch (\Throwable) {
            // FQDN can be assigned on the target after import.
        }

        // Skip Application::created (settings + default NIXPACKS env) so import hydrates from the manifest.
        Application::withoutEvents(fn () => $application->save());
        ApplicationSetting::create(['application_id' => $application->id]);
        $this->syncSettings($application, $resource['settings'] ?? []);
        $application->load('settings');

        try {
            if ($application->destination->server->proxyType() !== 'NONE' && $application->settings?->is_container_label_readonly_enabled === true) {
                $customLabels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
                $application->custom_labels = base64_encode($customLabels);
                $application->save();
            }
        } catch (\Throwable) {
            // FQDN labels can be regenerated on the target after import.
        }

        $this->syncEnvironmentVariables($application, $resource['environment_variables'] ?? [], $uuidMap);
        $this->syncVolumes($application, $resource['volumes'] ?? [], $resource['source_uuid']);
        $this->syncFileStorages($application, $resource['file_storages'] ?? []);

        return $application->fresh();
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $uuidMap
     */
    private function importService(
        array $resource,
        StandaloneDocker|SwarmDocker $destination,
        Environment $environment,
        array $uuidMap,
    ): Service {
        $uuid = new_public_id();
        $service = new Service;
        $service->fill($this->attributes($resource, $service));
        $service->uuid = $uuid;
        $service->name = $resource['name'] ?? ('migrated-'.$uuid);
        $service->environment_id = $environment->id;
        $service->destination_id = $destination->id;
        $service->destination_type = $destination->getMorphClass();
        $service->server_id = $destination->server_id;
        $service->save();

        $this->syncEnvironmentVariables($service, $resource['environment_variables'] ?? [], $uuidMap);
        $service->parse();

        return $service->fresh();
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, string>  $uuidMap
     */
    private function importDatabase(
        array $resource,
        StandaloneDocker|SwarmDocker $destination,
        Environment $environment,
        array $uuidMap,
    ): Model {
        $type = str_replace('standalone-', '', (string) $resource['type']);
        $modelClass = STANDALONE_DATABASE_MODELS[$type] ?? null;
        if (! is_string($modelClass)) {
            throw new RuntimeException("Unknown database type [{$resource['type']}].");
        }

        $uuid = new_public_id();
        /** @var Model $database */
        $database = new $modelClass;
        $database->fill($this->attributes($resource, $database));
        $database->uuid = $uuid;
        $database->name = $resource['name'] ?? ('migrated-'.$uuid);
        $database->status = 'exited';
        $database->environment_id = $environment->id;
        $database->destination_id = $destination->id;
        $database->destination_type = $destination->getMorphClass();
        $database->save();

        $this->syncEnvironmentVariables($database, $resource['environment_variables'] ?? [], $uuidMap);
        $this->syncVolumes($database, $resource['volumes'] ?? [], $resource['source_uuid']);
        $this->syncFileStorages($database, $resource['file_storages'] ?? []);

        return $database->fresh();
    }

    /**
     * @param  array<string, mixed>  $resource
     * @return array<string, mixed>
     */
    private function attributes(array $resource, Model $model): array
    {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
        $columns = Schema::getColumnListing($model->getTable());

        return collect($attributes)
            ->only($columns)
            ->except(['id', 'uuid', 'destination_id', 'destination_type', 'environment_id', 'server_id', 'status'])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function syncSettings(Application $application, array $settings): void
    {
        unset($settings['id'], $settings['application_id'], $settings['created_at'], $settings['updated_at']);
        if ($settings === []) {
            return;
        }

        $columns = Schema::getColumnListing((new ApplicationSetting)->getTable());
        $payload = collect($settings)
            ->only((new ApplicationSetting)->getFillable())
            ->only($columns)
            ->all();

        if ($payload === []) {
            return;
        }

        $application->settings()->update($payload);
    }

    /**
     * @param  array<int, array<string, mixed>>  $variables
     * @param  array<string, string>  $uuidMap
     */
    private function syncEnvironmentVariables(Model $resource, array $variables, array $uuidMap): void
    {
        EnvironmentVariable::withoutEvents(function () use ($resource, $variables, $uuidMap): void {
            $resource->environment_variables()->delete();
            if (method_exists($resource, 'environment_variables_preview')) {
                $resource->environment_variables_preview()->delete();
            }

            foreach ($variables as $variable) {
                $environmentVariable = new EnvironmentVariable([
                    'key' => $variable['key'],
                    'value' => replace_database_uuids_in_value((string) ($variable['value'] ?? ''), $uuidMap),
                    'is_literal' => (bool) ($variable['is_literal'] ?? false),
                    'is_multiline' => (bool) ($variable['is_multiline'] ?? false),
                    'is_preview' => (bool) ($variable['is_preview'] ?? false),
                    'is_runtime' => (bool) ($variable['is_runtime'] ?? true),
                    'is_buildtime' => (bool) ($variable['is_buildtime'] ?? true),
                    'is_shared' => (bool) ($variable['is_shared'] ?? false),
                    'comment' => $variable['comment'] ?? null,
                    'resourceable_type' => $resource->getMorphClass(),
                    'resourceable_id' => $resource->id,
                ]);
                $environmentVariable->uuid = new_public_id();
                $environmentVariable->save();
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $volumes
     */
    private function syncVolumes(Model $resource, array $volumes, string $sourceUuid): void
    {
        if (method_exists($resource, 'persistentStorages')) {
            $resource->persistentStorages()->delete();
        }

        foreach ($volumes as $volume) {
            LocalPersistentVolume::create([
                'name' => generate_cloned_persistent_volume_name(
                    (string) $volume['name'],
                    $resource->uuid,
                    $sourceUuid,
                ),
                'mount_path' => $volume['mount_path'],
                'host_path' => $volume['host_path'] ?? null,
                'is_preview_suffix_enabled' => (bool) ($volume['is_preview_suffix_enabled'] ?? false),
                'resource_id' => $resource->id,
                'resource_type' => $resource->getMorphClass(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $storages
     */
    private function syncFileStorages(Model $resource, array $storages): void
    {
        foreach ($storages as $storage) {
            LocalFileVolume::withoutEvents(function () use ($resource, $storage): void {
                $fileVolume = new LocalFileVolume([
                    'fs_path' => $storage['fs_path'] ?? null,
                    'mount_path' => $storage['mount_path'],
                    'content' => $storage['content'] ?? null,
                    'is_directory' => (bool) ($storage['is_directory'] ?? false),
                    'is_host_file' => (bool) ($storage['is_host_file'] ?? false),
                    'chown' => $storage['chown'] ?? null,
                    'chmod' => $storage['chmod'] ?? null,
                    'is_based_on_git' => $storage['is_based_on_git'] ?? null,
                    'resource_id' => $resource->id,
                    'resource_type' => $resource->getMorphClass(),
                ]);
                $fileVolume->uuid = new_public_id();
                $fileVolume->save();
            });
        }
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function applyGitSource(Application $application, array $resource, int $teamId): void
    {
        $git = $resource['git'] ?? [];
        $sourceUuid = $git['source_uuid'] ?? null;
        if (is_string($sourceUuid) && $sourceUuid !== '') {
            $githubApp = GithubApp::where('uuid', $sourceUuid)->where('team_id', $teamId)->first();
            if ($githubApp) {
                $application->source_id = $githubApp->id;
                $application->source_type = $githubApp->getMorphClass();
            }
        }

        $privateKeyUuid = $git['private_key_uuid'] ?? null;
        if (is_string($privateKeyUuid) && $privateKeyUuid !== '') {
            $privateKey = PrivateKey::where('uuid', $privateKeyUuid)->where('team_id', $teamId)->first();
            if ($privateKey) {
                $application->private_key_id = $privateKey->id;
            }
        }
    }
}

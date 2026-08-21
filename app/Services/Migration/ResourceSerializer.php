<?php

namespace App\Services\Migration;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;

class ResourceSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function serialize(Model $resource): array
    {
        $resource->makeVisible($resource->getHidden());

        $payload = [
            'type' => $resource->type(),
            'source_uuid' => $resource->uuid,
            'name' => $resource->name,
            'attributes' => $this->attributes($resource),
            'environment_variables' => $this->environmentVariables($resource),
            'volumes' => $this->volumes($resource),
            'file_storages' => $this->fileStorages($resource),
            'warnings' => [],
        ];

        if ($resource instanceof Application) {
            $payload['settings'] = $resource->settings?->only($resource->settings->getFillable()) ?? [];
            $payload['git'] = $this->gitMetadata($resource, $payload['warnings']);
        }

        if ($resource instanceof Service) {
            $payload['attributes']['docker_compose_raw'] = $resource->docker_compose_raw;
            $payload['attributes']['docker_compose'] = $resource->docker_compose;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Model $resource): array
    {
        $excluded = [
            'id',
            'uuid',
            'destination_id',
            'destination_type',
            'environment_id',
            'server_id',
            'status',
            'started_at',
            'last_online_at',
            'restart_count',
            'last_restart_at',
            'last_restart_type',
            'config_hash',
            'source_id',
            'source_type',
            'private_key_id',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        return collect($resource->only($resource->getFillable()))
            ->except($excluded)
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function environmentVariables(Model $resource): array
    {
        if (! method_exists($resource, 'environment_variables')) {
            return [];
        }

        $variables = $resource->environment_variables()->get();
        if (method_exists($resource, 'environment_variables_preview')) {
            $variables = $variables->concat($resource->environment_variables_preview()->get());
        }

        return $variables->map(function (EnvironmentVariable $variable): array {
            $variable->makeVisible(['value', 'real_value']);

            return [
                'key' => $variable->key,
                'value' => $variable->value,
                'is_literal' => $variable->is_literal,
                'is_multiline' => $variable->is_multiline,
                'is_preview' => $variable->is_preview,
                'is_runtime' => $variable->is_runtime,
                'is_buildtime' => $variable->is_buildtime,
                'is_shared' => $variable->is_shared,
                'comment' => $variable->comment,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function volumes(Model $resource): array
    {
        if (! method_exists($resource, 'persistentStorages')) {
            return [];
        }

        return $resource->persistentStorages()->get()->map(fn (LocalPersistentVolume $volume): array => [
            'name' => $volume->name,
            'mount_path' => $volume->mount_path,
            'host_path' => $volume->host_path,
            'is_preview_suffix_enabled' => $volume->is_preview_suffix_enabled,
            'archive' => null,
        ])->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fileStorages(Model $resource): array
    {
        if (! method_exists($resource, 'fileStorages')) {
            return [];
        }

        return $resource->fileStorages()->get()->map(function (LocalFileVolume $storage): array {
            $storage->makeVisible(['content']);

            return [
                'fs_path' => $storage->fs_path,
                'mount_path' => $storage->mount_path,
                'content' => $storage->content,
                'is_directory' => $storage->is_directory,
                'is_host_file' => $storage->is_host_file,
                'chown' => $storage->chown,
                'chmod' => $storage->chmod,
                'is_based_on_git' => $storage->is_based_on_git,
                'archive' => null,
            ];
        })->all();
    }

    /**
     * @param  array<int, string>  $warnings
     * @return array<string, mixed>
     */
    private function gitMetadata(Application $application, array &$warnings): array
    {
        $git = [
            'repository' => $application->git_repository,
            'branch' => $application->git_branch,
            'source_type' => $application->source_type,
            'source_uuid' => $application->source instanceof GithubApp ? $application->source->uuid : null,
            'private_key_uuid' => $application->private_key_id
                ? PrivateKey::find($application->private_key_id)?->uuid
                : null,
        ];

        if ($application->source_id && ! $application->source instanceof GithubApp) {
            $warnings[] = 'Private Git source must be re-linked on the target team.';
        }

        if ($application->source instanceof GithubApp) {
            $warnings[] = 'Private GitHub App apps migrate only if the same GitHub App exists on the target team.';
        }

        if ($application->private_key_id) {
            $warnings[] = 'Deploy-key apps migrate only if an equivalent private key exists on the target team.';
        }

        return $git;
    }
}

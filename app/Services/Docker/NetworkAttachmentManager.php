<?php

namespace App\Services\Docker;

use App\Enums\NetworkAttachmentStatus;
use App\Models\Application;
use App\Models\DockerNetwork;
use App\Models\NetworkAttachment;
use App\Models\Service;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NetworkAttachmentManager
{
    public function __construct(private NetworkAttachableResolver $resolver) {}

    public function createDesiredAttachment(Model $resource, DockerNetwork $network, array $data): NetworkAttachment
    {
        $server = $this->resolver->resolveServer($resource);

        if (! $server || $network->server_id !== $server->id) {
            throw ValidationException::withMessages([
                'selectedNetworkId' => 'Network must belong to the same server as the resource.',
            ]);
        }

        $existingAttachment = $this->duplicateQuery($resource, $network)->first();

        if ($existingAttachment && ! $existingAttachment->is_runtime_discovered) {
            throw ValidationException::withMessages([
                'selectedNetworkId' => 'This resource already has an attachment for this network.',
            ]);
        }

        return DB::transaction(function () use ($resource, $network, $server, $data, $existingAttachment) {
            if ((bool) data_get($data, 'is_primary', false)) {
                $this->clearPrimary($resource);
            }

            if ($existingAttachment) {
                $existingAttachment->update([
                    'aliases' => $this->normalizeAliases((string) data_get($data, 'aliases', '')),
                    'is_primary' => (bool) data_get($data, 'is_primary', false),
                    'is_required' => (bool) data_get($data, 'is_required', false),
                    'is_managed' => true,
                    'is_runtime_discovered' => false,
                    'status' => $existingAttachment->status === NetworkAttachmentStatus::Attached
                        ? NetworkAttachmentStatus::Attached
                        : NetworkAttachmentStatus::Desired,
                ]);

                return $existingAttachment->refresh();
            }

            return NetworkAttachment::create([
                'server_id' => $server->id,
                'docker_network_id' => $network->id,
                'attachable_type' => $resource::class,
                'attachable_id' => $resource->id,
                'resource_type' => $this->resolver->resolveResourceType($resource),
                'resource_id' => $resource->id,
                'service_name' => data_get($data, 'service_name'),
                'container_name' => null,
                'container_id' => null,
                'aliases' => $this->normalizeAliases((string) data_get($data, 'aliases', '')),
                'is_primary' => (bool) data_get($data, 'is_primary', false),
                'is_required' => (bool) data_get($data, 'is_required', false),
                'is_managed' => true,
                'is_runtime_discovered' => false,
                'status' => NetworkAttachmentStatus::Desired,
                'last_checked_at' => null,
                'last_error' => null,
            ]);
        });
    }

    public function updateAttachment(NetworkAttachment $attachment, array $data): NetworkAttachment
    {
        if ($attachment->is_runtime_discovered) {
            throw ValidationException::withMessages([
                'attachment' => 'Runtime discovered attachments cannot be edited in this phase.',
            ]);
        }

        return DB::transaction(function () use ($attachment, $data) {
            if ((bool) data_get($data, 'is_primary', false)) {
                $this->clearPrimary($attachment->attachable, $attachment);
            }

            $attachment->update([
                'aliases' => $this->normalizeAliases((string) data_get($data, 'aliases', '')),
                'is_primary' => (bool) data_get($data, 'is_primary', false),
                'is_required' => (bool) data_get($data, 'is_required', false),
            ]);

            return $attachment->refresh();
        });
    }

    public function setPrimary(NetworkAttachment $attachment): NetworkAttachment
    {
        return DB::transaction(function () use ($attachment) {
            $this->clearPrimary($attachment->attachable, $attachment);
            $attachment->update(['is_primary' => true]);

            return $attachment->refresh();
        });
    }

    public function managedNetworkModeEnabled(Model $resource): bool
    {
        if ($resource instanceof Application) {
            return (bool) $resource->settings?->managed_network_mode;
        }

        if ($resource instanceof Service) {
            return (bool) $resource->managed_network_mode;
        }

        return false;
    }

    public function setManagedNetworkMode(Model $resource, bool $enabled): void
    {
        if ($resource instanceof Application && $resource->settings) {
            $resource->settings->update(['managed_network_mode' => $enabled]);
            $resource->refresh();
        }

        if ($resource instanceof Service) {
            $resource->update(['managed_network_mode' => $enabled]);
            $resource->refresh();
        }
    }

    public function syncManagedNetworkMode(Model $resource): bool
    {
        $enabled = NetworkAttachment::query()
            ->where('attachable_type', $resource::class)
            ->where('attachable_id', $resource->id)
            ->where('is_managed', true)
            ->where('is_runtime_discovered', false)
            ->exists();

        $this->setManagedNetworkMode($resource, $enabled);

        return $enabled;
    }

    public function deleteAttachmentConfiguration(NetworkAttachment $attachment): void
    {
        if ($attachment->status === NetworkAttachmentStatus::Attached) {
            throw ValidationException::withMessages([
                'attachment' => 'This attachment is currently attached. Disconnect it before removing the desired configuration.',
            ]);
        }

        if ($attachment->is_runtime_discovered) {
            throw ValidationException::withMessages([
                'attachment' => 'Runtime discovered attachments cannot be removed in this phase.',
            ]);
        }

        $attachment->delete();
    }

    /**
     * @return array<int, string>
     */
    public function normalizeAliases(string $aliases): array
    {
        $normalized = collect(explode(',', $aliases))
            ->map(fn (string $alias): string => trim($alias))
            ->filter()
            ->unique()
            ->values();

        $invalid = $normalized->first(fn (string $alias): bool => ! preg_match(ValidationPatterns::CONTAINER_NAME_PATTERN, $alias));

        if ($invalid !== null) {
            throw ValidationException::withMessages([
                'aliases' => 'Aliases must start with an alphanumeric character and contain only letters, numbers, dots, hyphens, and underscores.',
            ]);
        }

        return $normalized->all();
    }

    private function duplicateQuery(Model $resource, DockerNetwork $network)
    {
        return NetworkAttachment::query()
            ->where('attachable_type', $resource::class)
            ->where('attachable_id', $resource->id)
            ->where('docker_network_id', $network->id)
            ->whereNull('service_name');
    }

    private function clearPrimary(?Model $resource, ?NetworkAttachment $except = null): void
    {
        if (! $resource) {
            return;
        }

        NetworkAttachment::query()
            ->where('attachable_type', $resource::class)
            ->where('attachable_id', $resource->id)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->update(['is_primary' => false]);
    }
}

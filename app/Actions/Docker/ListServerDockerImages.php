<?php

namespace App\Actions\Docker;

use App\Models\Server;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ListServerDockerImages
{
    use AsAction;

    public function handle(Server $server): Collection
    {
        if (! $server->isFunctional()) {
            return collect([]);
        }

        $imagesOutput = instant_remote_process([
            "docker image ls -a --no-trunc --digests --format '{{json .}}'",
        ], $server, false);
        $containersOutput = instant_remote_process([
            "docker ps -a --format '{{json .}}'",
        ], $server, false);
        $containers = format_docker_command_output_to_json($containersOutput ?? '');

        return self::normalizeImages(
            format_docker_command_output_to_json($imagesOutput ?? ''),
            self::usedImageReferencesFromContainers($containers),
            $containers
        );
    }

    public static function normalizeImages(Collection $images, Collection $usedImages, ?Collection $containers = null): Collection
    {
        $usedImages = $usedImages
            ->map(fn ($image) => trim((string) $image))
            ->filter()
            ->unique()
            ->values();
        $containers = $containers ?? collect([]);

        return $images
            ->map(function (array $image) use ($usedImages, $containers) {
                $repository = self::normalizeDockerValue(data_get($image, 'Repository'));
                $tag = self::normalizeDockerValue(data_get($image, 'Tag'));
                $digest = self::normalizeDockerValue(data_get($image, 'Digest'));
                $id = self::normalizeDockerValue(data_get($image, 'ID'));
                $reference = self::imageReference($repository, $tag);
                $references = collect([$id, $reference])
                    ->when($repository && $digest, fn (Collection $items) => $items->push("{$repository}@{$digest}"))
                    ->filter()
                    ->unique()
                    ->values();
                $usedBy = self::containersUsingImage($containers, $references);

                return [
                    'id' => $id,
                    'repository' => $repository,
                    'tag' => $tag,
                    'digest' => $digest,
                    'reference' => $reference,
                    'created_at' => self::normalizeDockerValue(data_get($image, 'CreatedAt')),
                    'created_since' => self::normalizeDockerValue(data_get($image, 'CreatedSince')),
                    'size' => self::normalizeDockerValue(data_get($image, 'Size')),
                    'shared_size' => self::normalizeDockerValue(data_get($image, 'SharedSize')),
                    'unique_size' => self::normalizeDockerValue(data_get($image, 'UniqueSize')),
                    'virtual_size' => self::normalizeDockerValue(data_get($image, 'VirtualSize')),
                    'dangling' => ! $repository || ! $tag,
                    'in_use' => $usedBy->isNotEmpty() || $references->contains(fn ($reference) => $usedImages->contains($reference)),
                    'containers' => $usedBy->toArray(),
                ];
            })
            ->filter(fn (array $image) => filled(data_get($image, 'id')))
            ->sortBy([
                ['repository', 'asc'],
                ['tag', 'asc'],
                ['id', 'asc'],
            ], SORT_NATURAL)
            ->values();
    }

    public static function usedImageReferencesFromContainers(Collection $containers): Collection
    {
        return $containers
            ->map(fn (array $container) => data_get($container, 'Image'))
            ->filter()
            ->values();
    }

    public static function imageReference(?string $repository, ?string $tag): ?string
    {
        if (! $repository) {
            return null;
        }

        if (! $tag) {
            return $repository;
        }

        return "{$repository}:{$tag}";
    }

    private static function normalizeDockerValue(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '<none>') {
            return null;
        }

        return $value;
    }

    private static function containersUsingImage(Collection $containers, Collection $imageReferences): Collection
    {
        return $containers
            ->filter(fn (array $container) => $imageReferences->contains(data_get($container, 'Image')))
            ->map(fn (array $container) => [
                'id' => self::normalizeDockerValue(data_get($container, 'ID')),
                'name' => self::normalizeDockerValue(data_get($container, 'Names')),
                'state' => self::normalizeDockerValue(data_get($container, 'State')),
                'status' => self::normalizeDockerValue(data_get($container, 'Status')),
            ])
            ->values();
    }
}

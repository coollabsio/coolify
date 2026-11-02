<?php

namespace App\Actions\Application;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Lists the images an application can roll back to: the images on its server, and the images of
 * recent successful deployments that were pushed to the Docker registry. The deployment pulls a
 * registry image again when the server no longer has it.
 */
class GetRollbackImages
{
    use AsAction;

    public const REGISTRY_DEPLOYMENTS_LIMIT = 10;

    /**
     * @return array{current: ?string, images: list<array{tag: string, created_at: ?string, is_current: bool, source: 'local'|'registry'}>}
     */
    public function handle(Application $application): array
    {
        $current = null;
        $images = collect();

        $server = $application->destination?->server;
        if ($server?->isFunctional()) {
            $current = self::currentTag($server, $application);

            $repository = $application->docker_registry_image_name ?: $application->uuid;
            $output = instant_remote_process([
                "docker images --format '{{.Repository}}#{{.Tag}}#{{.CreatedAt}}'",
            ], $server);
            $images = str($output)->trim()->explode("\n")
                ->map(fn (string $line) => explode('#', $line))
                // Exact repository match only, so other images with a similar name are not listed.
                ->filter(fn (array $parts) => $parts[0] === $repository && filled($parts[1] ?? null))
                ->map(fn (array $parts) => [
                    'tag' => $parts[1],
                    'created_at' => $parts[2] ?? null,
                    'is_current' => $parts[1] === $current,
                    'source' => 'local',
                ])
                ->keyBy('tag');
        }

        foreach ($this->registryImages($application, $current) as $image) {
            if (! $images->has($image['tag'])) {
                $images->put($image['tag'], $image);
            }
        }

        return [
            'current' => $current,
            'images' => $images->values()->all(),
        ];
    }

    /**
     * The tag of the running production containers. Container names can have a timestamp or a
     * custom name, so find them by their owner labels. Null when no container runs, or when the
     * running containers use different tags (for example during a rolling update).
     */
    private static function currentTag(Server $server, Application $application): ?string
    {
        $tags = getCurrentApplicationContainerStatus($server, $application, 0)
            ->filter(fn ($container) => data_get($container, 'State') === 'running')
            ->map(fn ($container) => self::imageTag((string) data_get($container, 'Image')))
            ->unique();

        return $tags->count() === 1 ? $tags->first() : null;
    }

    /**
     * Each successful deployment pushes `<image name>:<commit>` to the registry, so the commits of
     * recent deployments are rollback targets. Docker image applications do not push, and
     * Dockerfile-only applications always push the `latest` tag.
     *
     * @return list<array{tag: string, created_at: ?string, is_current: bool, source: 'registry'}>
     */
    private function registryImages(Application $application, ?string $current): array
    {
        if (blank($application->docker_registry_image_name)
            || in_array($application->build_pack, ['dockerimage', 'dockercompose'], true)
            || filled($application->dockerfile)) {
            return [];
        }

        return $application->deployment_queue()
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', 0)
            ->whereNotNull('commit')
            ->where('commit', '!=', 'HEAD')
            ->select('commit')
            ->selectRaw('max(updated_at) as deployed_at')
            ->groupBy('commit')
            ->orderByDesc('deployed_at')
            ->limit(self::REGISTRY_DEPLOYMENTS_LIMIT)
            ->get()
            ->map(function ($deployment) use ($current) {
                // The same tag as the deployment job builds in generate_image_names().
                $tag = substr($deployment->commit, 0, 128);

                return [
                    'tag' => $tag,
                    'created_at' => $deployment->deployed_at,
                    'is_current' => $tag === $current,
                    'source' => 'registry',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The tag of an image reference such as `registry.example.com:5000/team/app:tag`, or null for a digest.
     */
    public static function imageTag(string $imageReference): ?string
    {
        if (str_contains($imageReference, '@')) {
            return null;
        }

        $lastColon = strrpos($imageReference, ':');
        $lastSlash = strrpos($imageReference, '/');

        if ($lastColon === false || ($lastSlash !== false && $lastColon < $lastSlash)) {
            return null;
        }

        return substr($imageReference, $lastColon + 1) ?: null;
    }
}

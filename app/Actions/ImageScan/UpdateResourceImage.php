<?php

namespace App\Actions\ImageScan;

use App\Actions\Database\RestartDatabase;
use App\Actions\Service\RestartService;
use App\Models\Application;
use App\Models\ImageScan;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\User;
use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\ImageUpdateRefused;
use App\Services\ImageScan\ResourceScans;
use App\Support\ResourceStartActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Switches a resource to a candidate image that fixes vulnerabilities (spec 7.3). Only a better candidate of the
 * resource's current scan is accepted: a newer tag of the same line changes the image and restarts or redeploys; a
 * newer build of the same tag pulls it and restarts. A major version is never accepted.
 *
 * Docker image app: changes the tag and queues a deploy. Standalone database: changes `image` and restarts. Service
 * (or one of its applications and databases): changes the image of every application and database of the service that
 * uses the scanned image, in the compose file too, then pulls and restarts the service.
 */
class UpdateResourceImage
{
    use AsAction;

    /**
     * @throws AuthorizationException When the user may not update the resource, or the resource belongs to Coolify (id 0).
     * @throws ImageUpdateRefused When the ref is not a better candidate, or the update cannot start.
     */
    public function handle(Model $resource, string $newRef, User $by): void
    {
        $service = match (true) {
            $resource instanceof Service => $resource,
            $resource instanceof ServiceApplication, $resource instanceof ServiceDatabase => $resource->service,
            default => null,
        };

        Gate::forUser($by)->authorize('update', $service ?? $resource);

        if ((int) $resource->getKey() === 0 || ($service !== null && (int) $service->getKey() === 0)) {
            throw new AuthorizationException('Coolify does not change the images of its own resources.');
        }

        if ($resource instanceof Application && $resource->build_pack !== 'dockerimage') {
            throw new ImageUpdateRefused('Coolify builds the image of this app, so there is no image to update.');
        }
        if (! $resource instanceof Application && $service === null && ! in_array($resource::class, STANDALONE_DATABASE_MODELS, true)) {
            throw new ImageUpdateRefused('Coolify cannot update the image of this resource.');
        }

        $owners = match (true) {
            $resource instanceof Service => ResourceScans::owners($resource),
            default => collect([$resource]),
        };
        [$owner, $scan, $candidate] = $this->candidate($owners, $newRef);

        $oldRef = (string) $scan->image_ref;
        $sameTag = $candidate['kind'] === 'same_tag';
        $newTag = (string) ImageRef::parse($newRef)->tag;

        $updated = match (true) {
            $service !== null => $this->updateService($service, $oldRef, $newTag, $sameTag),
            $owner instanceof Application => $this->updateApplication($owner, $newTag, $sameTag),
            default => $this->updateDatabase($owner, $newTag, $sameTag),
        };

        $target = $service ?? $owner;
        auditLog('ui.image_scan.image_updated', [
            'team_id' => $target->team()?->id,
            'resource_type' => $target->getMorphClass(),
            'resource_uuid' => (string) $target->getAttribute('uuid'),
            'resource_name' => (string) $target->getAttribute('name'),
            'updated_uuids' => $updated,
            'old_ref' => $oldRef,
            'new_ref' => $newRef,
            'kind' => $candidate['kind'],
            'user_id' => $by->id,
            'actor_id' => $by->id,
            'actor_name' => $by->name,
            'actor_email' => $by->email,
        ]);
    }

    /**
     * The owner, its current scan, and the candidate entry that matches the ref.
     *
     * @param  Collection<int, Model>  $owners
     * @return array{0: Model, 1: ImageScan, 2: array<string, mixed>}
     */
    private function candidate(Collection $owners, string $newRef): array
    {
        foreach ($owners as $owner) {
            foreach (ResourceScans::currentFor($owner) as $scan) {
                foreach ($scan->updateCandidates() as $entry) {
                    if ($entry['ref'] !== $newRef) {
                        continue;
                    }
                    $blocked = self::blockedReason($scan, $owner);
                    if ($blocked !== null) {
                        throw new ImageUpdateRefused($blocked);
                    }

                    return [$owner, $scan, $entry];
                }
            }
        }

        throw new ImageUpdateRefused('This version is not one that fixes vulnerabilities of the current image.');
    }

    /**
     * Why Coolify cannot switch the image of a scan, even with a better candidate. Null when it can.
     */
    public static function blockedReason(ImageScan $scan, Model $owner): ?string
    {
        if ($owner instanceof Application && $owner->build_pack === 'dockercompose') {
            return 'Change the version in the compose file of this app, then redeploy.';
        }

        if (str_contains((string) $owner->getAttribute('image'), '$')) {
            return 'The compose file sets this image with a variable. Change the version in the compose file.';
        }

        if (ResourceScans::runsOtherImage($scan, $owner)) {
            return 'The running image is not the scanned image. Scan it again to check for newer versions.';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function updateApplication(Application $application, string $newTag, bool $sameTag): array
    {
        $oldTag = $application->docker_registry_image_tag;
        if (! $sameTag) {
            $application->docker_registry_image_tag = $newTag;
            $application->save();
        }

        $restore = function () use ($application, $oldTag, $sameTag): void {
            if (! $sameTag) {
                $application->docker_registry_image_tag = $oldTag;
                $application->save();
            }
        };

        try {
            $result = queue_application_deployment(application: $application, deployment_uuid: new_public_id());
        } catch (Throwable $e) {
            $restore();
            report($e);

            throw new ImageUpdateRefused('The deploy could not start. Coolify kept the old image.', previous: $e);
        }

        if (($result['status'] ?? null) !== 'queued') {
            $restore();

            throw new ImageUpdateRefused(($result['status'] ?? null) === 'skipped'
                ? 'A deploy of this app is already queued. Try again when it is done.'
                : (string) ($result['message'] ?? 'The deploy could not be queued.'));
        }

        return [(string) $application->uuid];
    }

    /**
     * @return list<string>
     */
    private function updateDatabase(Model $database, string $newTag, bool $sameTag): array
    {
        $oldImage = (string) $database->getAttribute('image');
        if (! $sameTag) {
            $database->setAttribute('image', $this->withTag($oldImage, $newTag));
            $database->save();
        }

        $restore = function () use ($database, $oldImage, $sameTag): void {
            if (! $sameTag) {
                $database->setAttribute('image', $oldImage);
                $database->save();
            }
        };

        try {
            $result = RestartDatabase::run($database);
        } catch (Throwable $e) {
            $restore();
            report($e);

            throw new ImageUpdateRefused('The restart failed. Coolify kept the old image.', previous: $e);
        }

        if (is_string($result)) {
            $restore();

            throw new ImageUpdateRefused($result);
        }

        return [(string) $database->getAttribute('uuid')];
    }

    /**
     * @return list<string>
     */
    private function updateService(Service $service, string $oldRef, string $newTag, bool $sameTag): array
    {
        if (ResourceStartActivity::latestRunning((string) $service->uuid) !== null) {
            throw new ImageUpdateRefused('A start of this service is running. Try again when it is done.');
        }

        $subResources = $sameTag ? collect() : self::subResourcesUsing($service, $oldRef);
        $oldCompose = $service->docker_compose_raw;
        $oldImages = $subResources->mapWithKeys(fn (Model $subResource): array => [$subResource->getMorphClass().':'.$subResource->getKey() => $subResource->image])->all();

        if (! $sameTag) {
            $compose = $this->composeWithImages($service, $subResources, $newTag);
            DB::transaction(function () use ($service, $compose, $subResources, $newTag): void {
                $service->docker_compose_raw = Yaml::dump($compose, 10, 2);
                $service->save();
                foreach ($subResources as $subResource) {
                    $subResource->image = $this->withTag((string) $subResource->image, $newTag);
                    $subResource->save();
                }
            });
        }

        try {
            RestartService::run($service, true);
        } catch (Throwable $e) {
            if (! $sameTag) {
                DB::transaction(function () use ($service, $oldCompose, $subResources, $oldImages): void {
                    $service->docker_compose_raw = $oldCompose;
                    $service->save();
                    foreach ($subResources as $subResource) {
                        $subResource->image = $oldImages[$subResource->getMorphClass().':'.$subResource->getKey()];
                        $subResource->save();
                    }
                });
            }
            report($e);

            throw new ImageUpdateRefused('The restart failed. Coolify kept the old image.', previous: $e);
        }

        return $subResources->map(fn (Model $subResource): string => (string) $subResource->getAttribute('uuid'))->values()->all();
    }

    /**
     * The applications and databases of a service that run the image.
     *
     * @return Collection<int, ServiceApplication|ServiceDatabase>
     */
    public static function subResourcesUsing(Service $service, string $imageRef): Collection
    {
        try {
            $scanned = ImageRef::parse($imageRef);
        } catch (InvalidArgumentException) {
            return collect();
        }

        return ResourceScans::owners($service)
            ->filter(function (Model $subResource) use ($scanned): bool {
                try {
                    $image = ImageRef::parse(trim((string) $subResource->getAttribute('image')));
                } catch (InvalidArgumentException) {
                    return false;
                }

                return $image->digest === null && $scanned->digest === null
                    && $image->qualifiedName() === $scanned->qualifiedName()
                    && ($image->tag ?? 'latest') === ($scanned->tag ?? 'latest');
            })
            ->values();
    }

    /**
     * The compose file with the new image for each sub-resource. Refuses when the compose file names another image
     * for one of them, for example a variable, because then the image does not come from the field Coolify changes.
     *
     * @param  Collection<int, ServiceApplication|ServiceDatabase>  $subResources
     * @return array<string, mixed>
     */
    private function composeWithImages(Service $service, Collection $subResources, string $newTag): array
    {
        if ($subResources->isEmpty()) {
            throw new ImageUpdateRefused('No application or database of this service runs this image.');
        }

        try {
            $compose = parseDockerComposeYaml((string) $service->docker_compose_raw);
        } catch (ParseException) {
            $compose = null;
        }
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            throw new ImageUpdateRefused('The compose file of this service is not valid.');
        }

        foreach ($subResources as $subResource) {
            $name = (string) $subResource->name;
            $configured = $compose['services'][$name]['image'] ?? null;
            if (! is_string($configured) || trim($configured) !== trim((string) $subResource->image)) {
                throw new ImageUpdateRefused("The compose file sets the image of {$name} in another way. Change it in the compose file.");
            }
            $compose['services'][$name]['image'] = $this->withTag((string) $subResource->image, $newTag);
        }

        return $compose;
    }

    /**
     * The configured image with another tag, keeping how the user wrote the name (`postgres`, `docker.io/library/postgres`).
     */
    private function withTag(string $image, string $tag): string
    {
        $image = trim($image);
        $lastColon = strrpos($image, ':');
        $lastSlash = strrpos($image, '/');
        $name = $lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash) ? substr($image, 0, $lastColon) : $image;

        return $name.':'.$tag;
    }
}

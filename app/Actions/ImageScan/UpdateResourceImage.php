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

        $this->authorizeFor($service ?? $resource, $by, (int) $resource->getKey() === 0);

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
            $service !== null => $this->updateService($service, [['old' => $oldRef, 'tag' => $newTag, 'same_tag' => $sameTag]]),
            $owner instanceof Application => $this->updateApplication($owner, $newTag, $sameTag),
            default => $this->updateDatabase($owner, $newTag, $sameTag),
        };

        $this->audit($service ?? $owner, $by, $updated, [['old_ref' => $oldRef, 'new_ref' => $newRef, 'kind' => $candidate['kind']]]);
    }

    /**
     * Updates several images of one service in one change and one restart: the parts of a merged update ("Update n8n
     * and n8n runners to 2.10.4."). Each part must be a better candidate of the current scan of its own application
     * or database. When the restart fails, every image and the compose file are put back.
     *
     * @param  list<array{owner: Model, ref: string}>  $parts
     *
     * @throws AuthorizationException When the user may not update the service, or the service belongs to Coolify (id 0).
     * @throws ImageUpdateRefused When a part is not a better candidate, or the update cannot start.
     */
    public function handleParts(Service $service, array $parts, User $by): void
    {
        $this->authorizeFor($service, $by, false);

        if ($parts === []) {
            throw new ImageUpdateRefused('This update has no images.');
        }

        $owners = ResourceScans::owners($service);
        $changes = [];
        $log = [];
        foreach ($parts as $part) {
            $owner = $owners->first(fn (Model $candidate): bool => $candidate->getMorphClass() === $part['owner']->getMorphClass()
                && (string) $candidate->getKey() === (string) $part['owner']->getKey());
            if (! $owner instanceof Model) {
                throw new ImageUpdateRefused('An image of this update does not belong to this service.');
            }

            [, $scan, $candidate] = $this->candidate(collect([$owner]), $part['ref']);
            $changes[] = ['old' => (string) $scan->image_ref, 'tag' => (string) ImageRef::parse($part['ref'])->tag, 'same_tag' => $candidate['kind'] === 'same_tag'];
            $log[] = ['old_ref' => (string) $scan->image_ref, 'new_ref' => $part['ref'], 'kind' => $candidate['kind']];
        }

        $updated = $this->updateService($service, $changes);

        $this->audit($service, $by, $updated, $log);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeFor(Model $target, User $by, bool $isCoolifyOwner): void
    {
        Gate::forUser($by)->authorize('update', $target);

        if ($isCoolifyOwner || (int) $target->getKey() === 0) {
            throw new AuthorizationException('Coolify does not change the images of its own resources.');
        }
    }

    /**
     * @param  list<string>  $updated
     * @param  non-empty-list<array{old_ref: string, new_ref: string, kind: string}>  $changes
     */
    private function audit(Model $target, User $by, array $updated, array $changes): void
    {
        auditLog('ui.image_scan.image_updated', [
            'team_id' => $target->team()?->id,
            'resource_type' => $target->getMorphClass(),
            'resource_uuid' => (string) $target->getAttribute('uuid'),
            'resource_name' => (string) $target->getAttribute('name'),
            'updated_uuids' => $updated,
            'old_ref' => $changes[0]['old_ref'],
            'new_ref' => $changes[0]['new_ref'],
            'kind' => $changes[0]['kind'],
            ...(count($changes) > 1 ? ['changes' => $changes] : []),
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
     * Changes the images of the service to their new tags and restarts it once. A change of the same tag only pulls.
     *
     * @param  non-empty-list<array{old: string, tag: string, same_tag: bool}>  $changes
     * @return list<string>
     */
    private function updateService(Service $service, array $changes): array
    {
        if (ResourceStartActivity::latestRunning((string) $service->uuid) !== null) {
            throw new ImageUpdateRefused('A start of this service is running. Try again when it is done.');
        }

        /** @var array<string, array{resource: ServiceApplication|ServiceDatabase, tag: string}> $targets */
        $targets = [];
        foreach ($changes as $change) {
            if ($change['same_tag']) {
                continue;
            }
            $subResources = self::subResourcesUsing($service, $change['old']);
            if ($subResources->isEmpty()) {
                throw new ImageUpdateRefused('No application or database of this service runs this image.');
            }
            foreach ($subResources as $subResource) {
                $targets[$subResource->getMorphClass().':'.$subResource->getKey()] = ['resource' => $subResource, 'tag' => $change['tag']];
            }
        }

        $oldCompose = $service->docker_compose_raw;
        $oldImages = array_map(fn (array $target): string => (string) $target['resource']->image, $targets);

        if ($targets !== []) {
            $compose = $this->composeWithImages($service, $targets);
            DB::transaction(function () use ($service, $compose, $targets): void {
                $service->docker_compose_raw = Yaml::dump($compose, 10, 2);
                $service->save();
                foreach ($targets as $target) {
                    $target['resource']->image = $this->withTag((string) $target['resource']->image, $target['tag']);
                    $target['resource']->save();
                }
            });
        }

        try {
            RestartService::run($service, true);
        } catch (Throwable $e) {
            if ($targets !== []) {
                DB::transaction(function () use ($service, $oldCompose, $targets, $oldImages): void {
                    $service->docker_compose_raw = $oldCompose;
                    $service->save();
                    foreach ($targets as $key => $target) {
                        $target['resource']->image = $oldImages[$key];
                        $target['resource']->save();
                    }
                });
            }
            report($e);

            throw new ImageUpdateRefused('The restart failed. Coolify kept the old image.', previous: $e);
        }

        return array_values(array_map(fn (array $target): string => (string) $target['resource']->getAttribute('uuid'), $targets));
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
     * @param  array<string, array{resource: ServiceApplication|ServiceDatabase, tag: string}>  $targets
     * @return array<string, mixed>
     */
    private function composeWithImages(Service $service, array $targets): array
    {
        try {
            $compose = parseDockerComposeYaml((string) $service->docker_compose_raw);
        } catch (ParseException) {
            $compose = null;
        }
        if (! is_array($compose) || ! is_array($compose['services'] ?? null)) {
            throw new ImageUpdateRefused('The compose file of this service is not valid.');
        }

        foreach ($targets as $target) {
            $subResource = $target['resource'];
            $name = (string) $subResource->name;
            $configured = $compose['services'][$name]['image'] ?? null;
            if (! is_string($configured) || trim($configured) !== trim((string) $subResource->image)) {
                throw new ImageUpdateRefused("The compose file sets the image of {$name} in another way. Change it in the compose file.");
            }
            $compose['services'][$name]['image'] = $this->withTag((string) $subResource->image, $target['tag']);
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

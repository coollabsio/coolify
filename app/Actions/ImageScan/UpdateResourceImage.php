<?php

namespace App\Actions\ImageScan;

use App\Actions\Database\RestartDatabase;
use App\Actions\Service\RestartService;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\ImageScan;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\User;
use App\Services\ImageScan\ComposeImageVariable;
use App\Services\ImageScan\ImageRef;
use App\Services\ImageScan\ImageStartPin;
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
 * resource's current scan is accepted. A major version is never accepted.
 *
 * A newer tag of the same line changes the configuration, so it stays after later deploys and restarts:
 * - Docker image app: the tag, then a deploy. Standalone database: `image`, then a restart.
 * - Service: the image line of every application and database that uses the image, in the compose file too; or the
 *   Coolify environment variable when a variable sets the image (`${IMAGE}` or `repo:${TAG}`); then a restart.
 * - Compose app: the compose file comes from Git, so Coolify keeps an image override per Compose service
 *   (`docker_compose_image_overrides`) that the parser applies while the file still names the replaced image; or the
 *   environment variable as above; then a deploy.
 *
 * A newer build of the same tag changes no configuration: the next start pulls the scanned digest and gives it the tag
 * (`ImageStartPin`), so the containers run exactly the build that Coolify scanned. Every path puts the old
 * configuration back when the restart or deploy cannot start.
 *
 * @phpstan-type ImageChange array{owner: Model, old: string, tag: string, same_tag: bool, digest: ?string}
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

        if ($resource instanceof Application && ! in_array($resource->build_pack, ['dockerimage', 'dockercompose'], true)) {
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
        $change = $this->change($owner, $scan, $candidate, $newRef);

        $updated = match (true) {
            $service !== null => $this->updateService($service, [$change]),
            $owner instanceof Application && $owner->build_pack === 'dockercompose' => $this->updateComposeApplication($owner, [$change]),
            $owner instanceof Application => $this->updateApplication($owner, $change),
            default => $this->updateDatabase($owner, $change),
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
            $changes[] = $this->change($owner, $scan, $candidate, $part['ref']);
            $log[] = ['old_ref' => (string) $scan->image_ref, 'new_ref' => $part['ref'], 'kind' => $candidate['kind']];
        }

        $updated = $this->updateService($service, $changes);

        $this->audit($service, $by, $updated, $log);
    }

    /**
     * @throws AuthorizationException
     * @throws ImageUpdateRefused When another Coolify instance manages the server now.
     */
    private function authorizeFor(Model $target, User $by, bool $isCoolifyOwner): void
    {
        Gate::forUser($by)->authorize('update', $target);

        if ($isCoolifyOwner || (int) $target->getKey() === 0) {
            throw new AuthorizationException('Coolify does not change the images of its own resources.');
        }

        // Another Coolify instance manages a transferred server; this one keeps only an archive of it.
        $server = $target instanceof Service ? $target->server : $target->getAttribute('destination')?->server;
        if ($server instanceof Server && $server->isTransferredAway()) {
            throw new ImageUpdateRefused('Another Coolify instance manages this server now.');
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
            $refused = self::composeRefusal($owner);
            if ($refused !== null) {
                return $refused;
            }
        }

        $image = (string) $owner->getAttribute('image');
        if (ComposeImageVariable::hasVariable($image) && ComposeImageVariable::parse($image) === null) {
            return 'The compose file sets this image in a way Coolify cannot change. Change the version in the compose file.';
        }

        if (ResourceScans::runsOtherImage($scan, $owner)) {
            return 'The running image is not the scanned image. Scan it again to check for newer versions.';
        }

        return null;
    }

    /**
     * One image change: the owner, the scanned image, the new tag, and for a newer build of the same tag its digest.
     *
     * @param  array<string, mixed>  $candidate
     * @return ImageChange
     */
    private function change(Model $owner, ImageScan $scan, array $candidate, string $newRef): array
    {
        return [
            'owner' => $owner,
            'old' => (string) $scan->image_ref,
            'tag' => (string) ImageRef::parse($newRef)->tag,
            'same_tag' => $candidate['kind'] === 'same_tag',
            'digest' => is_string($candidate['digest'] ?? null) ? $candidate['digest'] : null,
        ];
    }

    /**
     * Waits the scanned build of a newer build of the same tag for the next start of `$starter`.
     *
     * @param  ImageChange  $change
     */
    private function pin(Model $starter, array $change): void
    {
        if ($change['digest'] === null) {
            throw new ImageUpdateRefused('Coolify does not know the digest of the newer build. Check for newer versions again.');
        }

        ImageStartPin::put($starter, $change['old'], $change['digest']);
    }

    /**
     * @param  ImageChange  $change
     * @return list<string>
     */
    private function updateApplication(Application $application, array $change): array
    {
        $oldTag = $application->docker_registry_image_tag;
        if ($change['same_tag']) {
            $this->pin($application, $change);
        } else {
            $application->docker_registry_image_tag = $change['tag'];
            $application->save();
        }

        $this->deploy($application, function () use ($application, $oldTag, $change): void {
            if (! $change['same_tag']) {
                $application->docker_registry_image_tag = $oldTag;
                $application->save();
            }
        });

        return [(string) $application->uuid];
    }

    /**
     * A Compose app from Git: an image override per Compose service, or the environment variable that sets the image,
     * then a deploy. Refused while the app deploys its compose file as written.
     *
     * @param  non-empty-list<ImageChange>  $changes
     * @return list<string>
     */
    private function updateComposeApplication(Application $application, array $changes): array
    {
        $refused = self::composeRefusal($application);
        if ($refused !== null) {
            throw new ImageUpdateRefused($refused);
        }

        $services = self::composeServices($application);
        $oldOverrides = $application->docker_compose_image_overrides;
        $overrides = $application->activeComposeImageOverrides();
        $variables = [];
        $variableServices = [];
        $pinned = false;

        foreach ($changes as $change) {
            if ($change['same_tag']) {
                $this->pin($application, $change);
                $pinned = true;

                continue;
            }

            $matched = false;
            foreach ($services as $name => $image) {
                $variable = ComposeImageVariable::parse($image);
                if ($variable !== null) {
                    $current = $variable->resolve($this->variableValue($application, $variable->key));
                    if ($current !== null && self::sameImage($current, $change['old'])) {
                        $variables = $this->addVariable($variables, $variable->key, $variable->valueFor($current, $change['tag']));
                        $variableServices[$name] = true;
                        $matched = true;
                    }

                    continue;
                }

                $current = $overrides[$name]['to'] ?? $image;
                if (! ComposeImageVariable::hasVariable($image) && self::sameImage($current, $change['old'])) {
                    $overrides[$name] = ['from' => $image, 'to' => $this->withTag($current, $change['tag'])];
                    $matched = true;
                }
            }
            if (! $matched) {
                throw new ImageUpdateRefused('No service of the compose file runs this image in a way Coolify can change. Change the version in the compose file.');
            }
        }

        // A variable that also sets the image of a service that this update does not change would change that image too.
        foreach ($services as $name => $image) {
            $variable = ComposeImageVariable::parse($image);
            if ($variable !== null && isset($variables[$variable->key]) && ! isset($variableServices[$name])) {
                throw new ImageUpdateRefused("The variable {$variable->key} also sets the image of {$name}. Change the versions in the compose file.");
            }
        }

        $application->docker_compose_image_overrides = $overrides === [] ? null : $overrides;
        $application->save();
        $restoreVariables = $this->setVariables($application, $variables);

        $this->deploy($application, function () use ($application, $oldOverrides, $restoreVariables, $pinned): void {
            $application->docker_compose_image_overrides = $oldOverrides;
            $application->save();
            $restoreVariables();
            if ($pinned) {
                ImageStartPin::forget($application);
            }
        });

        return [(string) $application->uuid];
    }

    /**
     * Queues a deploy of the app; runs `$restore` and refuses when it cannot start.
     */
    private function deploy(Application $application, callable $restore): void
    {
        try {
            $result = queue_application_deployment(application: $application, deployment_uuid: new_public_id());
        } catch (Throwable $e) {
            $restore();
            ImageStartPin::forget($application);
            report($e);

            throw new ImageUpdateRefused('The deploy could not start. Coolify kept the old image.', previous: $e);
        }

        if (($result['status'] ?? null) !== 'queued') {
            $restore();
            ImageStartPin::forget($application);

            throw new ImageUpdateRefused(($result['status'] ?? null) === 'skipped'
                ? 'A deploy of this app is already queued. Try again when it is done.'
                : (string) ($result['message'] ?? 'The deploy could not be queued.'));
        }
    }

    /**
     * @param  ImageChange  $change
     * @return list<string>
     */
    private function updateDatabase(Model $database, array $change): array
    {
        $oldImage = (string) $database->getAttribute('image');
        if ($change['same_tag']) {
            $this->pin($database, $change);
        } else {
            $database->setAttribute('image', $this->withTag($oldImage, $change['tag']));
            $database->save();
        }

        $restore = function () use ($database, $oldImage, $change): void {
            ImageStartPin::forget($database);
            if (! $change['same_tag']) {
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
     * Changes the images of the service to their new tags and restarts it once: the compose file and the image of each
     * application and database, or the environment variable that sets the image. A newer build of the same tag only
     * waits its scanned digest for the restart.
     *
     * @param  non-empty-list<ImageChange>  $changes
     * @return list<string>
     */
    private function updateService(Service $service, array $changes): array
    {
        if (ResourceStartActivity::latestRunning((string) $service->uuid) !== null) {
            throw new ImageUpdateRefused('A start of this service is running. Try again when it is done.');
        }

        /** @var array<string, array{resource: ServiceApplication|ServiceDatabase, tag: string}> $targets */
        $targets = [];
        $variables = [];
        $variableOwners = [];
        $pinned = false;
        foreach ($changes as $change) {
            if ($change['same_tag']) {
                $this->pin($service, $change);
                $pinned = true;

                continue;
            }

            $raw = trim((string) $change['owner']->getAttribute('image'));
            if (ComposeImageVariable::hasVariable($raw)) {
                $variable = ComposeImageVariable::parse($raw);
                $current = ResourceScans::configuredImage($change['owner']);
                if ($variable === null || ! self::sameImage($current, $change['old'])) {
                    throw new ImageUpdateRefused('The compose file sets this image in a way Coolify cannot change. Change the version in the compose file.');
                }
                $this->refuseSharedVariable($service, $variable->key, $changes);
                $variables = $this->addVariable($variables, $variable->key, $variable->valueFor($current, $change['tag']));
                $variableOwners[] = (string) $change['owner']->getAttribute('uuid');

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
        $restoreVariables = $this->setVariables($service, $variables);

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
            $restoreVariables();
            if ($pinned) {
                ImageStartPin::forget($service);
            }
            report($e);

            throw new ImageUpdateRefused('The restart failed. Coolify kept the old image.', previous: $e);
        }

        return array_values(array_unique([
            ...array_map(fn (array $target): string => (string) $target['resource']->getAttribute('uuid'), $targets),
            ...$variableOwners,
        ]));
    }

    /**
     * Why Coolify cannot change the images of a Compose app at all. Null when it can.
     */
    private static function composeRefusal(Application $application): ?string
    {
        if ($application->settings?->is_raw_compose_deployment_enabled) {
            return 'This app deploys its compose file as written. Change the version in the compose file, then redeploy.';
        }
        if ((int) $application->compose_parsing_version < 3) {
            return 'Change the version in the compose file of this app, then redeploy.';
        }

        return null;
    }

    /**
     * The images of the services of the compose file from Git, as written, keyed by the service name.
     *
     * @return array<string, string>
     */
    private static function composeServices(Application $application): array
    {
        try {
            $services = parseDockerComposeYaml((string) $application->docker_compose_raw)['services'] ?? null;
        } catch (Throwable) {
            $services = null;
        }
        if (! is_array($services)) {
            throw new ImageUpdateRefused('The compose file of this app is not valid.');
        }

        $images = [];
        foreach ($services as $name => $service) {
            $image = is_array($service) ? ($service['image'] ?? null) : null;
            if (is_string($image) && trim($image) !== '') {
                $images[(string) $name] = trim($image);
            }
        }

        return $images;
    }

    private static function sameImage(string $a, string $b): bool
    {
        try {
            $first = ImageRef::parse(trim($a));
            $second = ImageRef::parse(trim($b));
        } catch (InvalidArgumentException) {
            return false;
        }

        return $first->digest === null && $second->digest === null
            && $first->qualifiedName() === $second->qualifiedName()
            && ($first->tag ?? 'latest') === ($second->tag ?? 'latest');
    }

    /**
     * The value of a Coolify environment variable of the resource; null when it is not set.
     */
    private function variableValue(Application|Service $resource, string $key): ?string
    {
        $variable = $resource->environment_variables()->where('key', $key)->where('is_preview', false)->first();

        return $variable instanceof EnvironmentVariable ? (string) $variable->value : null;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function addVariable(array $variables, string $key, string $value): array
    {
        if (isset($variables[$key]) && $variables[$key] !== $value) {
            throw new ImageUpdateRefused("The variable {$key} sets more than one of these images. Change the versions in the compose file.");
        }
        $variables[$key] = $value;

        return $variables;
    }

    /**
     * A variable that also sets the image of another application or database that this update does not change would
     * change that image too.
     *
     * @param  non-empty-list<ImageChange>  $changes
     */
    private function refuseSharedVariable(Service $service, string $key, array $changes): void
    {
        $changing = array_map(fn (array $change): string => $change['owner']->getMorphClass().':'.$change['owner']->getKey(), $changes);
        foreach (ResourceScans::owners($service) as $owner) {
            $image = ComposeImageVariable::parse((string) $owner->getAttribute('image'));
            if ($image !== null && $image->key === $key && ! in_array($owner->getMorphClass().':'.$owner->getKey(), $changing, true)) {
                throw new ImageUpdateRefused("The variable {$key} also sets the image of {$owner->getAttribute('name')}. Change the versions in the compose file.");
            }
        }
    }

    /**
     * Sets the environment variables of the resource, and returns what puts the old values back. A value that refers
     * to a shared variable or a secret is not replaced.
     *
     * @param  array<string, string>  $variables
     */
    private function setVariables(Application|Service $resource, array $variables): callable
    {
        $old = [];
        foreach ($variables as $key => $value) {
            $variable = $resource->environment_variables()->where('key', $key)->where('is_preview', false)->first();
            if ($variable instanceof EnvironmentVariable && str_contains((string) $variable->value, '{{')) {
                throw new ImageUpdateRefused("The variable {$key} refers to another variable. Change the version there.");
            }
        }

        foreach ($variables as $key => $value) {
            $variable = $resource->environment_variables()->where('key', $key)->where('is_preview', false)->first();
            if ($variable instanceof EnvironmentVariable) {
                $old[$key] = ['variable' => $variable, 'value' => $variable->value, 'created' => false];
                $variable->value = $value;
                $variable->save();
            } else {
                $old[$key] = ['variable' => $resource->environment_variables()->create(['key' => $key, 'value' => $value, 'is_preview' => false]), 'value' => null, 'created' => true];
            }
        }

        return function () use ($old): void {
            foreach ($old as $entry) {
                if ($entry['created']) {
                    $entry['variable']->delete();
                } else {
                    $entry['variable']->value = $entry['value'];
                    $entry['variable']->save();
                }
            }
        };
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

<?php

use App\Actions\Database\RestartDatabase;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Actions\Service\RestartService;
use App\Livewire\ImageScan\Vulnerabilities;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\ImageScan\BaseImageCheck;
use App\Services\ImageScan\ImageStartPin;
use App\Services\ImageScan\ImageUpdateRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Js;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Cache::flush();
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array']);
    $this->withoutVite();
    $this->withoutDefer();
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->admin = updateImageMember($this->team, 'admin');
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'main-server']);
    $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->database = updateImageDatabase();
});

function updateImageMember(Team $team, string $role = 'member', ?array $access = null): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user->fresh();
}

function updateImageDatabase(array $attributes = []): StandalonePostgresql
{
    return StandalonePostgresql::forceCreate([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'image' => 'postgres:15.0-alpine',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        ...$attributes,
    ]);
}

function updateImageApplication(): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
    ]);
}

/**
 * A Compose app from Git with the given images per service.
 *
 * @param  array<string, string>  $images
 */
function updateImageComposeApplication(array $images): Application
{
    $compose = ['services' => []];
    foreach ($images as $name => $image) {
        $compose['services'][$name] = ['image' => $image];
    }

    $application = Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => Yaml::dump($compose, 10, 2),
        'compose_parsing_version' => '5',
    ]);

    return $application->fresh();
}

const UPDATE_IMAGE_NEW_BUILD = 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function updateImageEntry(string $ref, string $kind = 'line', bool $better = true, array $overrides = []): array
{
    return [
        'ref' => $ref,
        'digest' => 'sha256:'.str_repeat('b', 64),
        'kind' => $kind,
        'better' => $better,
        'fixed_ids' => ['CVE-2025-1094'],
        'fixes' => ['critical' => 2, 'high' => 15, 'medium' => 4],
        'left' => ['critical' => 3, 'high' => 0, 'medium' => 1],
        'eol' => null,
        'scanned_at' => now()->toIso8601String(),
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $entries
 */
function updateImageScan(Model $resource, string $imageRef, array $entries, string $status = 'ok'): ImageScan
{
    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => $imageRef,
        'repo_digest' => 'sha256:'.str_repeat('a', 64),
        'findings' => [[
            'id' => 'CVE-2025-1094', 'aliases' => [], 'severity' => 'critical', 'package' => 'libpq', 'version' => '15.0',
            'type' => 'apk', 'artifactId' => 'a1', 'fixedIn' => '15.10', 'fixState' => 'fixed', 'kev' => false,
            'epss' => 0.2, 'risk' => 1.0, 'summary' => 'A bug.', 'layer' => 'base',
        ]],
        'summary' => ['truncated' => false, 'eol_distro' => null],
        'candidates' => ['status' => $status, 'checked_at' => now()->toIso8601String(), 'error' => null, 'tags_truncated' => false, 'entries' => $entries],
        'scanned_at' => now(),
    ]);
}

function updateImageService(array $images): Service
{
    $compose = ['services' => []];
    foreach ($images as $name => $image) {
        $compose['services'][$name] = ['image' => $image];
    }

    return Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => Yaml::dump($compose, 10, 2),
    ]);
}

function updateImageSubResource(Service $service, string $name, string $image, string $class = ServiceApplication::class): Model
{
    return $class::forceCreate([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => $name,
        'image' => $image,
    ]);
}

function updateImagePage(User $user, Model $resource, string $type, ?Team $sessionTeam = null): Testable
{
    test()->actingAs($user);
    session(['currentTeam' => $sessionTeam ?? test()->team]);

    return Livewire::test(Vulnerabilities::class, ['resourceType' => $type, 'resourceUuid' => $resource->uuid]);
}

describe('standalone database', function () {
    it('changes the image and restarts the database', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $this->admin);

        expect($this->database->fresh()->image)->toBe('postgres:15.15-alpine');
    });

    it('keeps how the image name was written', function () {
        $database = updateImageDatabase(['image' => 'docker.io/library/postgres:15.0-alpine']);
        updateImageScan($database, 'docker.io/library/postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        UpdateResourceImage::run($database, 'postgres:15.15-alpine', $this->admin);

        expect($database->fresh()->image)->toBe('docker.io/library/postgres:15.15-alpine');
    });

    it('puts the old image back when the restart cannot start', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn('Server is not functional');

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'Server is not functional');
        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine');
    });

    it('puts the old image back when the restart throws', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andThrow(new RuntimeException('ssh: connect to host failed'));

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The restart failed. Coolify kept the old image.');
        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine');
    });

    it('waits the scanned build of the same tag for the restart, and keeps the image', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.0-alpine', 'same_tag')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        UpdateResourceImage::run($this->database, 'postgres:15.0-alpine', $this->admin);

        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine')
            ->and(ImageStartPin::take($this->database))->toBe(['docker.io/library/postgres:15.0-alpine' => [
                'ref' => 'postgres:15.0-alpine', 'pull' => 'docker.io/library/postgres@'.UPDATE_IMAGE_NEW_BUILD,
            ]]);
    });

    it('drops the waiting build when the restart cannot start', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.0-alpine', 'same_tag')]);
        RestartDatabase::shouldRun()->once()->andReturn('Server is not functional');

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:15.0-alpine', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'Server is not functional');
        expect(ImageStartPin::take($this->database))->toBe([]);
    });

    it('records the update with the team of the database', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);
        $this->actingAs($this->admin);
        session(['currentTeam' => Team::factory()->create()]);

        UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $this->admin);

        $event = AuditEvent::query()->where('event', 'ui.image_scan.image_updated')->sole();
        expect($event->team_id)->toBe($this->team->id)
            ->and($event->resource_uuid)->toBe($this->database->uuid)
            ->and($event->actor_id)->toBe($this->admin->id)
            ->and(data_get($event->metadata, 'old_ref'))->toBe('postgres:15.0-alpine')
            ->and(data_get($event->metadata, 'new_ref'))->toBe('postgres:15.15-alpine');
    });
});

describe('refusals', function () {
    it('refuses a ref that is not a better candidate', function (string $ref) {
        updateImageScan($this->database, 'postgres:15.0-alpine', [
            updateImageEntry('postgres:15.14-alpine', 'line', false),
            updateImageEntry('postgres:16.4-alpine', 'major', false, ['fixes' => null, 'left' => null]),
        ], 'no_better');
        RestartDatabase::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($this->database, $ref, $this->admin))->toThrow(ImageUpdateRefused::class);
        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine');
    })->with([
        'a same-line candidate that is not better' => 'postgres:15.14-alpine',
        'a major version' => 'postgres:16.4-alpine',
        'a ref that was never a candidate' => 'postgres:15.20-alpine',
    ]);

    it('refuses a major entry even when it is marked better', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:16.4-alpine', 'major')]);
        RestartDatabase::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:16.4-alpine', $this->admin))->toThrow(ImageUpdateRefused::class);
    });

    it('refuses when the database runs another image than the scanned one', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        $this->database->forceFill(['image' => 'postgres:15.3-alpine'])->save();
        RestartDatabase::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The running image is not the scanned image.');
    });

    it('refuses Coolify\'s own database (id 0)', function () {
        Team::forceCreate(['id' => 0, 'name' => 'Root team', 'personal_team' => false]);
        $this->admin->teams()->attach(0, ['role' => 'admin']);
        $database = updateImageDatabase(['id' => 0, 'name' => 'coolify-db']);
        updateImageScan($database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($database, 'postgres:15.15-alpine', $this->admin->fresh()))
            ->toThrow(AuthorizationException::class);
        expect($database->fresh()->image)->toBe('postgres:15.0-alpine');
    });
});

describe('authorization', function () {
    it('allows a user with write access', function () {
        $writer = updateImageMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->database->uuid, 'levels' => ['read', 'write']],
        ]);
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $writer);

        expect($this->database->fresh()->image)->toBe('postgres:15.15-alpine');
    });

    it('forbids users without write access', function (?array $access) {
        $user = updateImageMember($this->team, 'member', $access === null ? null : [
            ['scope' => 'resource', 'uuid' => $this->database->uuid, 'levels' => $access],
        ]);
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $user))->toThrow(AuthorizationException::class);
        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine');
    })->with([
        'read only' => [null],
        'deploy only' => [['read', 'deploy']],
    ]);

    it('forbids a user of another team', function () {
        $stranger = updateImageMember(Team::factory()->create(), 'owner');
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        expect(fn () => UpdateResourceImage::run($this->database, 'postgres:15.15-alpine', $stranger))->toThrow(AuthorizationException::class);
    });
});

describe('docker image app', function () {
    it('changes the tag and queues a deploy', function () {
        $application = updateImageApplication();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        UpdateResourceImage::run($application, 'nginx:1.29', $this->admin);

        expect($application->fresh()->docker_registry_image_tag)->toBe('1.29')
            ->and($application->fresh()->docker_registry_image_name)->toBe('nginx')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
    });

    it('puts the old tag back when the deploy queue is full', function () {
        $application = updateImageApplication();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        $this->server->settings->forceFill(['deployment_queue_limit' => 0])->saveQuietly();

        expect(fn () => UpdateResourceImage::run($application, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'Deployment queue is full.');
        expect($application->fresh()->docker_registry_image_tag)->toBe('1.27');
    });

    it('puts the old tag back when a deploy is already queued', function () {
        $application = updateImageApplication();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'deployment_uuid' => 'queued-deploy',
            'server_id' => $this->server->id,
            'commit' => 'HEAD',
            'pull_request_id' => 0,
            'status' => 'queued',
        ]);

        expect(fn () => UpdateResourceImage::run($application, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'A deploy of this app is already queued.');
        expect($application->fresh()->docker_registry_image_tag)->toBe('1.27')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
    });

    it('refuses an app that Coolify builds', function () {
        $application = updateImageApplication();
        $application->forceFill(['build_pack' => 'dockerfile'])->save();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        expect(fn () => UpdateResourceImage::run($application, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'Coolify builds the image of this app, so there is no image to update.');
        expect($application->fresh()->docker_registry_image_tag)->toBe('1.27');
    });

    it('redeploys the scanned build of the same tag without a change', function () {
        $application = updateImageApplication();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.27', 'same_tag')]);

        UpdateResourceImage::run($application, 'nginx:1.27', $this->admin);

        expect($application->fresh()->docker_registry_image_tag)->toBe('1.27')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1)
            ->and(array_column(ImageStartPin::take($application), 'pull'))->toBe(['docker.io/library/nginx@'.UPDATE_IMAGE_NEW_BUILD]);
    });
});

describe('service', function () {
    it('changes every application and database that uses the old image, and no other', function () {
        $service = updateImageService(['web' => 'nginx:1.27', 'worker' => 'nginx:1.27', 'cache' => 'nginx:1.26', 'db' => 'postgres:16']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        $worker = updateImageSubResource($service, 'worker', 'nginx:1.27');
        $cache = updateImageSubResource($service, 'cache', 'nginx:1.26');
        $db = updateImageSubResource($service, 'db', 'postgres:16', ServiceDatabase::class);
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->once()->withArgs(fn (Service $restarted, bool $pull) => $restarted->is($service) && $pull)->andReturn(new Activity);

        UpdateResourceImage::run($web, 'nginx:1.29', $this->admin);

        $compose = Yaml::parse($service->fresh()->docker_compose_raw);
        expect($web->fresh()->image)->toBe('nginx:1.29')
            ->and($worker->fresh()->image)->toBe('nginx:1.29')
            ->and($cache->fresh()->image)->toBe('nginx:1.26')
            ->and($db->fresh()->image)->toBe('postgres:16')
            ->and(data_get($compose, 'services.web.image'))->toBe('nginx:1.29')
            ->and(data_get($compose, 'services.worker.image'))->toBe('nginx:1.29')
            ->and(data_get($compose, 'services.cache.image'))->toBe('nginx:1.26')
            ->and(data_get($compose, 'services.db.image'))->toBe('postgres:16');
    });

    it('changes the variable that sets the tag of the image, and keeps the compose file', function () {
        $service = updateImageService(['web' => 'nginx:${NGINX_VERSION:-1.27}']);
        $raw = $service->docker_compose_raw;
        $web = updateImageSubResource($service, 'web', 'nginx:${NGINX_VERSION:-1.27}');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->once()->andReturn(new Activity);

        updateImagePage($this->admin, $service, 'service')->assertSeeHtml('updateImage(');
        UpdateResourceImage::run($web, 'nginx:1.29', $this->admin);

        expect($service->environment_variables()->where('key', 'NGINX_VERSION')->value('value'))->toBe('1.29')
            ->and($service->fresh()->docker_compose_raw)->toBe($raw)
            ->and($web->fresh()->image)->toBe('nginx:${NGINX_VERSION:-1.27}');
    });

    it('changes the variable that sets the whole image', function () {
        $service = updateImageService(['web' => '${WEB_IMAGE}']);
        $web = updateImageSubResource($service, 'web', '${WEB_IMAGE}');
        $service->environment_variables()->create(['key' => 'WEB_IMAGE', 'value' => 'docker.io/library/nginx:1.27', 'is_preview' => false]);
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->once()->andReturn(new Activity);

        UpdateResourceImage::run($web, 'nginx:1.29', $this->admin);

        expect($service->environment_variables()->where('key', 'WEB_IMAGE')->value('value'))->toBe('docker.io/library/nginx:1.29');
    });

    it('puts the variable back when the restart fails', function () {
        $service = updateImageService(['web' => 'nginx:${NGINX_VERSION:-1.27}']);
        $web = updateImageSubResource($service, 'web', 'nginx:${NGINX_VERSION:-1.27}');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->once()->andThrow(new RuntimeException('ssh: connect to host failed'));

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The restart failed. Coolify kept the old image.');
        expect($service->environment_variables()->where('key', 'NGINX_VERSION')->exists())->toBeFalse();
    });

    it('refuses a variable that also sets the image of another application', function () {
        $service = updateImageService(['web' => 'nginx:${VERSION:-1.27}', 'proxy' => 'acme/proxy:${VERSION:-1.27}']);
        $web = updateImageSubResource($service, 'web', 'nginx:${VERSION:-1.27}');
        updateImageSubResource($service, 'proxy', 'acme/proxy:${VERSION:-1.27}');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The variable VERSION also sets the image of proxy.');
        expect($service->environment_variables()->where('key', 'VERSION')->exists())->toBeFalse();
    });

    it('refuses a variable that refers to a shared variable', function () {
        $service = updateImageService(['web' => 'nginx:${NGINX_VERSION}']);
        $web = updateImageSubResource($service, 'web', 'nginx:${NGINX_VERSION}');
        $service->environment_variables()->create(['key' => 'NGINX_VERSION', 'value' => '{{team.NGINX_VERSION}}', 'is_preview' => false]);
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))->toThrow(ImageUpdateRefused::class);
        expect($service->environment_variables()->where('key', 'NGINX_VERSION')->value('value'))->toBe('{{team.NGINX_VERSION}}');
    });

    it('offers no update for an image that the compose file sets in a way Coolify cannot change', function () {
        $service = updateImageService(['web' => '${REPO}:${VERSION}']);
        $web = updateImageSubResource($service, 'web', '${REPO}:${VERSION}');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The compose file sets this image in a way Coolify cannot change. Change the version in the compose file.');
        updateImagePage($this->admin, $service, 'service')->assertDontSeeHtml('updateImage(');
    });

    it('refuses when the compose file names another image than the application', function () {
        $service = updateImageService(['web' => 'nginx:1.26']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The compose file sets the image of web in another way.');
    });

    it('restores the compose file and the images when the restart fails', function () {
        $service = updateImageService(['web' => 'nginx:1.27', 'worker' => 'nginx:1.27']);
        $raw = $service->docker_compose_raw;
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        $worker = updateImageSubResource($service, 'worker', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->once()->andThrow(new RuntimeException('ssh: connect to host failed'));

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The restart failed. Coolify kept the old image.');
        expect($service->fresh()->docker_compose_raw)->toBe($raw)
            ->and($web->fresh()->image)->toBe('nginx:1.27')
            ->and($worker->fresh()->image)->toBe('nginx:1.27')
            ->and(AuditEvent::query()->where('event', 'ui.image_scan.image_updated')->exists())->toBeFalse();
    });

    it('pulls and restarts the service with the scanned build of the same tag', function () {
        $service = updateImageService(['web' => 'nginx:1.27']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.27', 'same_tag')]);
        RestartService::shouldRun()->once()->withArgs(fn (Service $restarted, bool $pull) => $restarted->is($service) && $pull)->andReturn(new Activity);

        UpdateResourceImage::run($web, 'nginx:1.27', $this->admin);

        expect($web->fresh()->image)->toBe('nginx:1.27')
            ->and(array_column(ImageStartPin::take($service), 'ref'))->toBe(['nginx:1.27']);
    });

    it('authorizes against the service', function () {
        $service = updateImageService(['web' => 'nginx:1.27']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        $reader = updateImageMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $service->uuid, 'levels' => ['read', 'deploy']],
        ]);
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $reader))->toThrow(AuthorizationException::class);
    });
});

describe('compose app', function () {
    it('keeps the new image as an override of the compose file from Git and deploys', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27', 'db' => 'postgres:16']);
        $raw = $application->docker_compose_raw;
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        UpdateResourceImage::run($application, 'nginx:1.29', $this->admin);

        $application->refresh();
        expect($application->docker_compose_image_overrides)->toBe(['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.29']])
            ->and($application->docker_compose_raw)->toBe($raw)
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1)
            ->and(data_get($application->parse(), 'services.web.image'))->toBe('nginx:1.29')
            ->and(data_get($application->parse(), 'services.db.image'))->toBe('postgres:16');
    });

    it('stops the override when the compose file in Git names another image', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        $application->forceFill(['docker_compose_image_overrides' => ['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.29']]])->save();
        $application->forceFill(['docker_compose_raw' => Yaml::dump(['services' => ['web' => ['image' => 'nginx:1.30']]], 10, 2)])->save();

        expect(data_get($application->fresh()->parse(), 'services.web.image'))->toBe('nginx:1.30')
            ->and($application->fresh()->activeComposeImageOverrides())->toBe([]);
    });

    it('updates an image that an override already replaced', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        $application->forceFill(['docker_compose_image_overrides' => ['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.29']]])->save();
        updateImageScan($application, 'nginx:1.29', [updateImageEntry('nginx:1.31')]);

        UpdateResourceImage::run($application, 'nginx:1.31', $this->admin);

        expect($application->fresh()->docker_compose_image_overrides)->toBe(['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.31']]);
    });

    it('changes the variable that sets the image of a compose service', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:${NGINX_VERSION:-1.27}']);
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        UpdateResourceImage::run($application, 'nginx:1.29', $this->admin);

        expect($application->environment_variables()->where('key', 'NGINX_VERSION')->value('value'))->toBe('1.29')
            ->and($application->fresh()->docker_compose_image_overrides)->toBeNull();
    });

    it('deploys the scanned build of the same tag without an override', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.27', 'same_tag')]);

        UpdateResourceImage::run($application, 'nginx:1.27', $this->admin);

        expect($application->fresh()->docker_compose_image_overrides)->toBeNull()
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1)
            ->and(array_column(ImageStartPin::take($application), 'ref'))->toBe(['nginx:1.27']);
    });

    it('puts the overrides back when the deploy cannot be queued', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        $this->server->settings->forceFill(['deployment_queue_limit' => 0])->saveQuietly();

        expect(fn () => UpdateResourceImage::run($application, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'Deployment queue is full.');
        expect($application->fresh()->docker_compose_image_overrides)->toBeNull();
    });

    it('refuses a compose app that deploys its compose file as written', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        $application->settings->forceFill(['is_raw_compose_deployment_enabled' => true])->save();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        expect(fn () => UpdateResourceImage::run($application->fresh(), 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'This app deploys its compose file as written.');
        expect($application->fresh()->docker_compose_image_overrides)->toBeNull();
    });

    it('shows the override on the Security card and removes it', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        $application->forceFill(['docker_compose_image_overrides' => ['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.29']]])->save();
        $application->forceFill(['docker_compose' => Yaml::dump(['services' => ['web' => ['image' => 'nginx:1.29']]], 10, 2)])->save();
        updateImageScan($application, 'nginx:1.29', [updateImageEntry('nginx:1.31')]);

        updateImagePage($this->admin, $application, 'application')
            ->assertSee('instead of')
            ->assertSee('Use the compose file')
            ->call('removeImageOverride', 'web')
            ->assertDispatched('success');

        expect($application->fresh()->docker_compose_image_overrides)->toBeNull();
    });

    it('forbids a read-only member to remove an override', function () {
        $application = updateImageComposeApplication(['web' => 'nginx:1.27']);
        $application->forceFill(['docker_compose_image_overrides' => ['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.29']]])->save();
        updateImageScan($application, 'nginx:1.29', [updateImageEntry('nginx:1.31')]);
        $reader = updateImageMember($this->team, 'member', [['scope' => 'resource', 'uuid' => $application->uuid, 'levels' => ['read']]]);

        updateImagePage($reader, $application, 'application')->call('removeImageOverride', 'web')->assertForbidden();

        expect($application->fresh()->docker_compose_image_overrides)->toBe(['web' => ['from' => 'nginx:1.27', 'to' => 'nginx:1.29']]);
    });
});

describe('main card actions', function () {
    it('confirms the update of a database with the change and the patch release note', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSee('Update to 15.15-alpine to fix it.')
            ->assertSee('postgres:15.0-alpine aaaaaaaaaaaa → postgres:15.15-alpine')
            ->assertSee('Restart the database.')
            ->assertSee('This is a patch release of PostgreSQL 15. Your data stays as it is. The database restarts and is unavailable for a few seconds.')
            ->assertSeeHtml('updateImage(');
    });

    it('offers pull latest and restart for a newer build of the same tag', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.0-alpine', 'same_tag')]);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSeeText('postgres:15.0-alpine has a security problem that attackers are likely to use. A newer build fixes it. Pull it and restart.')
            ->assertSee('Pull latest and restart')
            ->assertSee('Coolify pulls exactly the build that it scanned, not a build pushed after the check. The settings keep postgres:15.0-alpine, so later restarts pull its newest build as usual.');
    });

    it('never offers a newer major version', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:16.4-alpine', 'major', true, ['fixes' => null, 'left' => null])]);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSee('No fix yet')
            ->assertDontSee('Update to 16.4-alpine')
            ->assertDontSeeHtml('updateImage(');
    });

    it('asks for a new check while Coolify could not check for newer versions', function (string $status) {
        updateImageScan($this->database, 'postgres:15.0-alpine', [], $status);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSeeText('postgres:15.0-alpine needs another check: Coolify could not check for newer versions.')
            ->assertDontSeeHtml('updateImage(');
    })->with(['failed']);

    it('shows the real status and why no version is offered when the registry needs a login', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [], 'auth_required');

        updateImagePage($this->admin, $this->database, 'database')
            ->assertDontSee('needs another check')
            ->assertSee("Coolify cannot read the tags of postgres. Log in to docker.io on {$this->server->name}.")
            ->assertDontSeeHtml('updateImage(');
    });

    it('shows the action without the button to a read-only member', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        updateImagePage(updateImageMember($this->team), $this->database, 'database')
            ->assertSee('Update to 15.15-alpine to fix it.')
            ->assertDontSeeHtml('updateImage(');
    });

    it('lists the service applications that change', function () {
        $service = updateImageService(['web' => 'nginx:1.27', 'worker' => 'nginx:1.27']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        // The worker runs the same image but is left out of the status, so only the web scan counts.
        updateImageSubResource($service, 'worker', 'nginx:1.27')->forceFill(['exclude_from_status' => true])->save();
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        updateImagePage($this->admin, $service, 'service')
            ->assertSee('web: nginx:1.27 aaaaaaaaaaaa → nginx:1.29')
            ->assertSee('worker: nginx:1.27 → nginx:1.29')
            ->assertSee('One-click services pin versions that work together. Check the service template before you update.')
            ->assertSeeHtml($web->uuid);
    });

    it('tells Coolify\'s own database to update Coolify, without a button, while the findings stay', function () {
        Team::forceCreate(['id' => 0, 'name' => 'Root team', 'personal_team' => false]);
        $this->admin->teams()->attach(0, ['role' => 'admin']);
        $database = updateImageDatabase(['id' => 0, 'name' => 'coolify-db']);
        updateImageScan($database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        updateImagePage($this->admin->fresh(), $database, 'database')
            ->assertSee('libpq')
            ->assertSee('Update Coolify to get a newer version.')
            ->assertDontSee('Update to 15.15-alpine')
            ->assertDontSeeHtml('updateImage(');
    });

    it('names the distro that the update moves off', function () {
        $scan = updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        $scan->forceFill(['summary' => [...$scan->summary, 'eol_distro' => ['name' => 'alpine', 'version' => '3.16']]])->save();

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSee('Update to 15.15-alpine to fix it. It moves off Alpine 3.16, which no longer gets security fixes.');
    });

    it('offers no update while the database runs another image than the scanned one', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        $this->database->forceFill(['image' => 'postgres:15.15-alpine'])->save();

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSeeText('needs another check: the running image is not the checked image.')
            ->assertDontSeeHtml('updateImage(');
    });
});

describe('updateImage action', function () {
    it('updates the image and confirms that the update started', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        updateImagePage($this->admin, $this->database, 'database')
            ->call('updateImage', 'postgres:15.15-alpine', '', '')
            ->assertDispatched('success', 'Update started.')
            ->assertSeeText('needs another check: the running image is not the checked image.')
            ->assertDontSeeHtml('updateImage(');

        expect($this->database->fresh()->image)->toBe('postgres:15.15-alpine');
    });

    it('shows why an update was refused', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->never();

        updateImagePage($this->admin, $this->database, 'database')
            ->call('updateImage', 'postgres:16.4-alpine', '')
            ->assertDispatched('error');
    });

    it('shows an error instead of failing when the update throws', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        UpdateResourceImage::shouldRun()->once()->andThrow(new RuntimeException('unexpected'));

        updateImagePage($this->admin, $this->database, 'database')
            ->call('updateImage', 'postgres:15.15-alpine', '')
            ->assertOk()
            ->assertDispatched('error', 'The update could not start.');
    });

    it('shows the restart failure as an error', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andThrow(new RuntimeException('ssh: connect to host failed'));

        updateImagePage($this->admin, $this->database, 'database')
            ->call('updateImage', 'postgres:15.15-alpine', '')
            ->assertOk()
            ->assertDispatched('error', 'The restart failed. Coolify kept the old image.');

        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine');
    });

    it('forbids a read-only member', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->never();

        updateImagePage(updateImageMember($this->team), $this->database, 'database')
            ->call('updateImage', 'postgres:15.15-alpine', '')
            ->assertForbidden();
    });

    it('authorizes against the team of the resource after the session switched teams', function () {
        $writer = updateImageMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->database->uuid, 'levels' => ['read', 'write']],
        ]);
        $ownTeam = Team::factory()->create();
        $writer->teams()->attach($ownTeam, ['role' => 'owner']);
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        $page = updateImagePage($writer->fresh(), $this->database, 'database');
        session(['currentTeam' => $ownTeam]);
        $page->call('updateImage', 'postgres:15.15-alpine', '')->assertDispatched('success', 'Update started.');

        $event = AuditEvent::query()->where('event', 'ui.image_scan.image_updated')->sole();
        expect($this->database->fresh()->image)->toBe('postgres:15.15-alpine')
            ->and($event->team_id)->toBe($this->team->id);
    });

    it('updates the service applications from the service page', function () {
        $service = updateImageService(['web' => 'nginx:1.27']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->once()->andReturn(new Activity);

        updateImagePage($this->admin, $service, 'service')
            ->call('updateImage', 'nginx:1.29', $web->uuid, '')
            ->assertDispatched('success', 'Update started.');

        expect($web->fresh()->image)->toBe('nginx:1.29');
    });
});

describe('base image of a Dockerfile build', function () {
    /**
     * A Dockerfile build from node:20.11-alpine. The base check found 7 findings at the alert level that the current
     * base image has too (2 critical, 5 high); CVE-2026-0030 comes from a package the Dockerfile added.
     *
     * @param  array<string, mixed>|null  $base
     */
    function updateImageDockerfileScan(?array $base, string $baseImageRef = 'node:20.11-alpine'): array
    {
        $application = Application::factory()->create([
            'environment_id' => test()->environment->id,
            'destination_id' => test()->destination->id,
            'destination_type' => test()->destination->getMorphClass(),
            'build_pack' => 'dockerfile',
        ]);
        $scan = ImageScan::factory()->create([
            'server_id' => test()->server->id,
            'resource_type' => $application->getMorphClass(),
            'resource_id' => $application->id,
            'image_ref' => $application->uuid.':abc123',
            'base_image_ref' => $baseImageRef,
            'builds_image' => true,
            'findings' => array_map(fn (array $row) => [
                'id' => $row[0], 'aliases' => [], 'severity' => $row[1], 'package' => 'openssl', 'version' => '3.1.4-r0',
                'type' => 'apk', 'artifactId' => $row[0], 'fixedIn' => '3.1.7-r0', 'fixState' => 'fixed', 'kev' => false,
                'epss' => null, 'risk' => 1.0, 'summary' => '', 'layer' => 'app',
            ], [
                ['CVE-2026-0001', 'critical'], ['CVE-2026-0002', 'critical'], ['CVE-2026-0011', 'high'], ['CVE-2026-0012', 'high'],
                ['CVE-2026-0013', 'high'], ['CVE-2026-0014', 'high'], ['CVE-2026-0015', 'high'], ['CVE-2026-0030', 'critical'],
            ]),
            'summary' => ['truncated' => false, 'eol_distro' => null],
            'candidates' => ['status' => 'skipped', 'checked_at' => now()->toIso8601String(), 'error' => null, 'tags_truncated' => false, 'entries' => [],
                ...($base === null ? [] : ['base' => [
                    'image' => $baseImageRef, 'checked_at' => now()->toIso8601String(), 'error' => null, 'tags_truncated' => false,
                    'attributable' => 7, 'current_digest' => 'sha256:'.str_repeat('c', 64),
                    'attributable_ids' => ['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0011', 'CVE-2026-0012', 'CVE-2026-0013', 'CVE-2026-0014', 'CVE-2026-0015'],
                    ...$base,
                ]])],
            'scanned_at' => now(),
        ]);

        return [$application, $scan];
    }

    function updateImageBaseEntry(array $overrides = []): array
    {
        return updateImageEntry('node:20.18-alpine', 'line', true, [
            'fixed_ids' => ['CVE-2026-0001', 'CVE-2026-0011', 'CVE-2026-0012', 'CVE-2026-0013', 'CVE-2026-0014', 'CVE-2026-0030'],
            'fixes' => ['critical' => 1, 'high' => 4, 'medium' => 0],
            ...$overrides,
        ]);
    }

    it('recommends the newer base image with a copy button for the FROM line', function () {
        [$application] = updateImageDockerfileScan(['status' => 'ok', 'entries' => [updateImageBaseEntry()]]);

        updateImagePage($this->admin, $application, 'application')
            ->assertSeeText('has 5 serious problems (1 critical, 4 high). Change the base image to node:20.18-alpine in your Dockerfile, then redeploy.')
            ->assertSeeHtml("copy(await ('FROM node:20.18-alpine'))")
            ->assertDontSee('Update to')
            ->assertDontSeeHtml('updateImage(');
    });

    it('counts what the newer base image fixes on the findings that are not ignored now', function (array $ignored, ?array $counts) {
        [$application, $scan] = updateImageDockerfileScan(['status' => 'ok', 'entries' => [updateImageBaseEntry()]]);
        foreach ($ignored as $id) {
            ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => $application->getMorphClass(), 'resource_id' => $application->id, 'vulnerability_id' => $id]);
        }

        $base = BaseImageCheck::baseImage($scan->fresh());
        expect($base === null ? null : [$base['fixed'], $base['total']])->toBe($counts);
    })->with([
        'nothing ignored' => [[], [5, 7]],
        'one not fixed ignored' => [['CVE-2026-0002'], [5, 6]],
        'one fixed ignored' => [['CVE-2026-0011'], [4, 6]],
        'all fixed ignored' => [['CVE-2026-0001', 'CVE-2026-0011', 'CVE-2026-0012', 'CVE-2026-0013', 'CVE-2026-0014'], null],
        'all attributable ignored' => [['CVE-2026-0001', 'CVE-2026-0002', 'CVE-2026-0011', 'CVE-2026-0012', 'CVE-2026-0013', 'CVE-2026-0014', 'CVE-2026-0015'], null],
    ]);

    it('keeps the name of the base image as the Dockerfile writes it', function () {
        [$application, $scan] = updateImageDockerfileScan(['status' => 'ok', 'entries' => [updateImageEntry('node:20.18-alpine', 'line', true, ['fixed_ids' => ['CVE-2026-0011', 'CVE-2026-0012']])]], 'docker.io/library/node:20.11-alpine');

        expect(BaseImageCheck::baseImage($scan->fresh()))->toBe(['from' => 'docker.io/library/node:20.11-alpine', 'to' => 'docker.io/library/node:20.18-alpine', 'fixed' => 2, 'total' => 7]);
        updateImagePage($this->admin, $application, 'application')
            ->assertSeeHtml('copy(await ('.Js::from('FROM docker.io/library/node:20.18-alpine').'))');
    });

    it('has no newer base image without a better line candidate that fixes something', function (?array $base) {
        [, $scan] = updateImageDockerfileScan($base);

        expect(BaseImageCheck::baseImage($scan->fresh()))->toBeNull();
    })->with([
        'not checked' => [null],
        'no better' => [['status' => 'no_better', 'entries' => [updateImageBaseEntry(['better' => false])]]],
        'failed' => [['status' => 'failed', 'error' => 'registry', 'entries' => []]],
        'daily limit' => [['status' => 'failed', 'error' => 'daily_limit', 'entries' => []]],
        'login needed' => [['status' => 'auth_required', 'error' => 'registry_auth', 'entries' => []]],
        'cannot compare' => [['status' => 'cannot_compare', 'entries' => [], 'attributable' => null]],
        'nothing attributable' => [['status' => 'ok', 'attributable' => 0, 'attributable_ids' => [], 'entries' => [updateImageBaseEntry()]]],
        'fixes only a finding the base image does not have' => [['status' => 'ok', 'entries' => [updateImageBaseEntry(['fixed_ids' => ['CVE-2026-0030']])]]],
    ]);
});

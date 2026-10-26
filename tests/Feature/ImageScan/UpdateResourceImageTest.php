<?php

use App\Actions\Database\RestartDatabase;
use App\Actions\ImageScan\UpdateResourceImage;
use App\Actions\Service\RestartService;
use App\Enums\ImageScanAlertLevel;
use App\Livewire\ImageScan\Vulnerabilities;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanPolicy;
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
use App\Services\ImageScan\FixPanel;
use App\Services\ImageScan\ImageUpdateRefused;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
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
    ImageScanPolicy::factory()->create(['scope_type' => $this->server->getMorphClass(), 'scope_id' => $this->server->id, 'alert_level' => 'high']);
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
        'summary' => ['fixable_now_at_level' => 1, 'truncated' => false, 'eol_distro' => null],
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

    it('pulls the newest build of the same tag and restarts', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.0-alpine', 'same_tag')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        UpdateResourceImage::run($this->database, 'postgres:15.0-alpine', $this->admin);

        expect($this->database->fresh()->image)->toBe('postgres:15.0-alpine');
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

    it('redeploys without a change for a newer build of the same tag', function () {
        $application = updateImageApplication();
        updateImageScan($application, 'nginx:1.27', [updateImageEntry('nginx:1.27', 'same_tag')]);

        UpdateResourceImage::run($application, 'nginx:1.27', $this->admin);

        expect($application->fresh()->docker_registry_image_tag)->toBe('1.27')
            ->and(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
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

    it('refuses when the compose file sets the image another way', function () {
        $service = updateImageService(['web' => 'nginx:${NGINX_VERSION:-1.27}']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::run($web, 'nginx:1.29', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'The compose file sets the image of web in another way.');
        expect($web->fresh()->image)->toBe('nginx:1.27');
    });

    it('pulls and restarts the service for a newer build of the same tag', function () {
        $service = updateImageService(['web' => 'nginx:1.27']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.27', 'same_tag')]);
        RestartService::shouldRun()->once()->withArgs(fn (Service $restarted, bool $pull) => $restarted->is($service) && $pull)->andReturn(new Activity);

        UpdateResourceImage::run($web, 'nginx:1.27', $this->admin);

        expect($web->fresh()->image)->toBe('nginx:1.27');
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

describe('fix panel', function () {
    it('shows the version to update to with what it fixes and leaves, and opens By CVE', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSet('view', 'cve')
            ->assertSee('Update to 15.15-alpine: fixes 17 critical and high. 3 critical are left, waiting for a fix from the image maintainer.')
            ->assertSee('Check the release notes. Some updates need a data migration.')
            ->assertSee('postgres:15.0-alpine → postgres:15.15-alpine');
    });

    it('says nothing fixable is left when the candidate leaves nothing at the alert level', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine', 'line', true, ['left' => ['critical' => 0, 'high' => 0, 'medium' => 5]])]);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSee('Update to 15.15-alpine: fixes 17 critical and high. Nothing fixable is left.');
    });

    it('offers pull latest and restart for a newer build of the same tag', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.0-alpine', 'same_tag')]);

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSee('A newer build of postgres:15.0-alpine fixes 17 critical and high.')
            ->assertSee('Pull latest and restart');
    });

    it('explains why there is no version to update to', function (string $status, string $imageRef, string $text, ?string $link) {
        updateImageScan($this->database, $imageRef, [], $status);

        $page = updateImagePage($this->admin, $this->database, 'database')
            ->assertSet('view', 'package')
            ->assertSee($text)
            ->assertDontSee('Update to');
        if ($link !== null) {
            $page->assertSeeHtml($link);
        }
    })->with([
        'no better version' => ['no_better', 'postgres:15.0-alpine', 'No newer version of postgres is better yet. Coolify checks again every day.', null],
        'cannot compare, Docker Hub official' => ['cannot_compare', 'postgres:latest', 'Coolify cannot compare versions of this image. Check the image page for a newer version.', 'https://hub.docker.com/_/postgres'],
        'cannot compare, Docker Hub namespace' => ['cannot_compare', 'bitnami/postgresql:latest', 'Coolify cannot compare versions of this image.', 'https://hub.docker.com/r/bitnami/postgresql'],
        'login needed' => ['auth_required', 'ghcr.io/acme/api:1.0', 'Coolify cannot read the tags of ghcr.io/acme/api. Log in to ghcr.io on main-server.', '/registries'],
    ]);

    it('shows a newer major version only in the details, without an action', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:16.4-alpine', 'major', false, ['fixes' => null, 'left' => null])], 'no_better');

        updateImagePage($this->admin, $this->database, 'database')
            ->assertSee('Newer major version')
            ->assertSee('postgres:16.4-alpine is available')
            ->assertDontSee('Update to 16.4-alpine');
    });

    it('shows the recommendation without the action to a read-only member', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        updateImagePage(updateImageMember($this->team), $this->database, 'database')
            ->assertSee('Update to 15.15-alpine: fixes 17 critical and high.')
            ->assertDontSeeHtml('updateImage(');
    });

    it('lists the service applications that change', function () {
        $service = updateImageService(['web' => 'nginx:1.27', 'worker' => 'nginx:1.27']);
        $web = updateImageSubResource($service, 'web', 'nginx:1.27');
        updateImageSubResource($service, 'worker', 'nginx:1.27');
        updateImageScan($web, 'nginx:1.27', [updateImageEntry('nginx:1.29')]);

        updateImagePage($this->admin, $service, 'service')
            ->assertSee('web: nginx:1.27 → nginx:1.29')
            ->assertSee('worker: nginx:1.27 → nginx:1.29')
            ->assertSee('One-click services pin versions that work together. Check the service template before you update.')
            ->assertSeeHtml($web->uuid);
    });

    it('is hidden for Coolify\'s own database, while the findings stay', function () {
        Team::forceCreate(['id' => 0, 'name' => 'Root team', 'personal_team' => false]);
        $this->admin->teams()->attach(0, ['role' => 'admin']);
        $database = updateImageDatabase(['id' => 0, 'name' => 'coolify-db']);
        updateImageScan($database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);

        updateImagePage($this->admin->fresh(), $database, 'database')
            ->assertSee('libpq')
            ->assertDontSee('Update to 15.15-alpine')
            ->assertDontSeeHtml('data-image-scan-fix-panel');
    });

    it('phrases the counts for the alert level', function () {
        expect(FixPanel::levelWords(ImageScanAlertLevel::Medium))->toBe('critical, high, and medium')
            ->and(FixPanel::levelWords(ImageScanAlertLevel::Critical))->toBe('critical')
            ->and(FixPanel::leftSentence(['critical' => 3, 'high' => 2, 'medium' => 1], ImageScanAlertLevel::Medium))
            ->toBe('3 critical, 2 high, and 1 medium are left, waiting for a fix from the image maintainer.')
            ->and(FixPanel::leftSentence(['critical' => 1, 'high' => 0, 'medium' => 4], ImageScanAlertLevel::High))
            ->toBe('1 critical is left, waiting for a fix from the image maintainer.')
            ->and(FixPanel::leftSentence(['critical' => 0, 'high' => 0, 'medium' => 4], ImageScanAlertLevel::Critical))
            ->toBe('Nothing fixable is left.');
    });
});

describe('updateImage action', function () {
    it('updates the image and confirms that the update started', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->once()->andReturn(new Activity);

        updateImagePage($this->admin, $this->database, 'database')
            ->call('updateImage', 'postgres:15.15-alpine', '', '')
            ->assertDispatched('success', 'Update started.');

        expect($this->database->fresh()->image)->toBe('postgres:15.15-alpine');
    });

    it('shows why an update was refused', function () {
        updateImageScan($this->database, 'postgres:15.0-alpine', [updateImageEntry('postgres:15.15-alpine')]);
        RestartDatabase::shouldRun()->never();

        updateImagePage($this->admin, $this->database, 'database')
            ->call('updateImage', 'postgres:16.4-alpine', '')
            ->assertDispatched('error');
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

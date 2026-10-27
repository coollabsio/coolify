<?php

use App\Actions\ImageScan\UpdateResourceImage;
use App\Actions\Service\RestartService;
use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\ImageScan\Vulnerabilities;
use App\Livewire\Project\Application\Deployment\Show;
use App\Livewire\Project\Application\Heading;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\ImageScan\ImageUpdateRefused;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\SecurityActions;
use App\Services\ImageScan\SecurityCard;
use App\Services\ImageScan\SecurityStatus;
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
    $this->admin = securityPageMember($this->team, 'admin');
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
});

function securityPageMember(Team $team, string $role = 'member', ?array $access = null): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function securityPageFinding(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'aliases' => [],
        'severity' => 'high',
        'package' => 'openssl',
        'version' => '3.0.1',
        'type' => 'apk',
        'artifactId' => 'a-'.$id,
        'fixedIn' => '3.0.2',
        'fixState' => 'fixed',
        'kev' => true,
        'epss' => null,
        'risk' => 1.0,
        'summary' => '',
        'layer' => 'app',
        'title' => 'Problem '.$id,
        'cvssScore' => 7.5,
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>
 */
function securityPageCandidates(array $entries, string $status = 'ok'): array
{
    return ['status' => $status, 'checked_at' => now()->toIso8601String(), 'error' => null, 'tags_truncated' => false, 'entries' => $entries];
}

/**
 * @param  list<string>  $fixedIds
 * @return array<string, mixed>
 */
function securityPageEntry(string $ref, string $kind, array $fixedIds): array
{
    return ['ref' => $ref, 'kind' => $kind, 'better' => true, 'fixed_ids' => $fixedIds, 'eol' => null];
}

/**
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function securityPageScan(Model $resource, string $imageRef, array $findings, array $attributes = []): ImageScan
{
    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => $imageRef,
        'builds_image' => false,
        'findings' => $findings,
        'candidates' => securityPageCandidates([], 'no_better'),
        'summary' => ['truncated' => false, 'eol_distro' => null],
        'grype_db_built_at' => now()->subDay(),
        'scanned_at' => now(),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function securityPageApplication(array $attributes = []): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function securityPageDeployment(Application $application, array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'deployment_uuid' => (string) Str::uuid(),
        'application_id' => $application->id,
        'server_id' => test()->server->id,
        'server_name' => test()->server->name,
        'destination_id' => test()->destination->id,
        'commit' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        ...$attributes,
    ]);
}

function securityPageDatabase(): StandalonePostgresql
{
    return StandalonePostgresql::forceCreate([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'image' => 'postgres:15.0',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

/**
 * A Dockerfile app from node:20.11-alpine, running the scanned commit: lodash (npm, exploited) needs a package
 * upgrade, openssl (apk, likely) a rebuild without cache, and busybox comes from the base image, which a newer base
 * fixes.
 */
function securityPageDockerfileApp(): Application
{
    $application = securityPageApplication(['build_pack' => 'dockerfile', 'docker_registry_image_name' => null]);
    $deployment = securityPageDeployment($application);
    securityPageScan($application, $application->uuid.':'.$deployment->commit, [
        securityPageFinding('CVE-2026-1000', ['package' => 'lodash', 'version' => '4.17.20', 'fixedIn' => '4.17.21', 'type' => 'npm', 'severity' => 'critical']),
        securityPageFinding('CVE-2026-2000', ['kev' => false, 'epss' => 0.5]),
        securityPageFinding('CVE-2026-3000', ['package' => 'busybox', 'kev' => false, 'severity' => 'critical', 'cvssScore' => 9.1]),
    ], [
        'deployment_queue_id' => $deployment->id,
        'builds_image' => true,
        'base_image_ref' => 'node:20.11-alpine',
        'candidates' => [...securityPageCandidates([], 'skipped'), 'base' => [
            'image' => 'node:20.11-alpine', 'status' => 'ok', 'checked_at' => now()->toIso8601String(), 'error' => null,
            'attributable' => 1, 'attributable_ids' => ['CVE-2026-3000'],
            'entries' => [securityPageEntry('node:20.18-alpine', 'line', ['CVE-2026-3000'])],
        ]],
    ]);

    return $application;
}

/**
 * A service that runs n8n and its runners at 1.0, both with a newer 1.1 that fixes a problem attackers are using.
 *
 * @return array{0: Service, 1: ServiceApplication, 2: ServiceApplication}
 */
function securityPageN8nService(): array
{
    $service = Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'docker_compose_raw' => Yaml::dump(['services' => [
            'n8n' => ['image' => 'n8nio/n8n:1.0'],
            'n8n runners' => ['image' => 'n8nio/runners:1.0'],
        ]], 10, 2),
    ]);
    $main = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'n8n', 'image' => 'n8nio/n8n:1.0']);
    $runners = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'n8n runners', 'image' => 'n8nio/runners:1.0']);
    foreach ([[$main, 'n8nio/n8n:1.1', 'CVE-2026-0001'], [$runners, 'n8nio/runners:1.1', 'CVE-2026-0002']] as [$owner, $candidate, $id]) {
        securityPageScan($owner, $owner->image, [securityPageFinding($id)], [
            'candidates' => securityPageCandidates([securityPageEntry($candidate, 'line', [$id])]),
        ]);
    }

    return [$service, $main, $runners];
}

function securityPage(User $user, Model $resource, string $type, ?Team $sessionTeam = null): Testable
{
    test()->actingAs($user);
    session(['currentTeam' => $sessionTeam ?? test()->team]);

    return Livewire::test(Vulnerabilities::class, ['resourceType' => $type, 'resourceUuid' => $resource->uuid]);
}

describe('main card', function () {
    it('tells a postgres 15.0 database to update now and why', function () {
        $database = securityPageDatabase();
        securityPageScan($database, 'postgres:15.0', [
            securityPageFinding('CVE-2025-1094', ['package' => 'libpq5', 'version' => '15.0', 'fixedIn' => '15.10', 'type' => 'deb', 'cvssScore' => 8.1, 'title' => 'SQL injection in psql quoting']),
        ], ['candidates' => securityPageCandidates([securityPageEntry('postgres:15.13', 'line', ['CVE-2025-1094'])])]);

        securityPage($this->admin, $database, 'database')
            ->assertSee('Update now')
            ->assertSee('This image has a security problem that attackers are using. Update to 15.13 to fix it.')
            ->assertSee('SQL injection in psql quoting')
            ->assertSee('CVE-2025-1094')
            ->assertSee('8.1 High')
            ->assertSee('Used by attackers')
            ->assertSee('Update to 15.13')
            ->assertSee('postgres:15.0 → postgres:15.13')
            ->assertSee('This is a patch release of PostgreSQL 15. Your data stays as it is. The database restarts and is unavailable for a few seconds.')
            ->assertSee('Checked');
    });

    it('offers to pull a newer build of the image of a Docker image app', function () {
        $application = securityPageApplication();
        securityPageScan($application, 'nginx:1.27', [securityPageFinding('CVE-2026-0001')], [
            'candidates' => securityPageCandidates([securityPageEntry('nginx:1.27', 'same_tag', ['CVE-2026-0001'])]),
        ]);

        securityPage($this->admin, $application, 'application')
            ->assertSee('A newer build of this image fixes it. Pull it and restart.')
            ->assertSee('Pull latest and restart')
            ->assertSee('Pull the newest build of nginx:1.27.')
            ->assertSeeHtml('updateImage(\\u0027nginx:1.27\\u0027');
    });

    it('gives a Dockerfile app a rebuild, the package to upgrade, and the newer base image', function () {
        $application = securityPageDockerfileApp();

        securityPage($this->admin, $application, 'application')
            ->assertSee('Rebuild without cache to get the fixed system packages.')
            ->assertSee('Coolify rebuilds commit a1b2c3d without cache and redeploys it.')
            ->assertSeeHtml('submitAction: \'rebuild\'')
            ->assertSee('Upgrade lodash to 4.17.21 or later, then redeploy.')
            ->assertSeeHtml("copy(await ('lodash 4.17.21'))")
            ->assertSee('Change the base image to node:20.18-alpine in your Dockerfile, then redeploy.')
            ->assertSeeHtml("copy(await ('FROM node:20.18-alpine'))");
    });

    it('says that only a builder update fixes the OS packages of a Nixpacks app', function () {
        $application = securityPageApplication(['build_pack' => 'nixpacks', 'docker_registry_image_name' => null]);
        $deployment = securityPageDeployment($application);
        securityPageScan($application, $application->uuid.':'.$deployment->commit, [securityPageFinding('CVE-2026-0001')], [
            'deployment_queue_id' => $deployment->id,
            'builds_image' => true,
        ]);

        securityPage($this->admin, $application, 'application')
            ->assertSee('No fix yet')
            ->assertSee('This image has a security problem that attackers are using. '.SecurityActions::BUILDER_TEXT)
            ->assertDontSeeHtml('rebuild');
    });

    it('updates the images of a service that share a version in one action', function () {
        [$service] = securityPageN8nService();

        securityPage($this->admin, $service, 'service')
            ->assertSee('This image has 2 security problems that attackers are using. Update n8n and n8n runners to 1.1 to fix them.')
            ->assertSee('Update to 1.1')
            ->assertSee('n8n: n8nio/n8n:1.0 → n8nio/n8n:1.1')
            ->assertSee('n8n runners: n8nio/runners:1.0 → n8nio/runners:1.1')
            ->assertSeeHtml('updateImages(');
    });

    it('says that no action is needed', function () {
        $application = securityPageApplication();
        securityPageScan($application, 'nginx:1.27', [securityPageFinding('CVE-2026-0001', ['kev' => false, 'severity' => 'medium', 'cvssScore' => 5.0])]);

        securityPage($this->admin, $application, 'application')
            ->assertSee('No action needed.')
            ->assertDontSee('Update now')
            ->assertDontSee('Security update');
    });

    it('asks for a new check with the reason', function () {
        $application = securityPageApplication();
        securityPageScan($application, 'nginx:1.27', [securityPageFinding('CVE-2026-0001')], ['scanned_at' => now()->subDays(3)]);

        securityPage($this->admin, $application, 'application')
            ->assertSee('Check needed')
            ->assertSee('Coolify needs to check this image again: the last check was 3 days ago.')
            ->assertSee('Scan now');
    });

    it('shows the actions to a read-only member without buttons', function () {
        $database = securityPageDatabase();
        securityPageScan($database, 'postgres:15.0', [securityPageFinding('CVE-2025-1094')], [
            'candidates' => securityPageCandidates([securityPageEntry('postgres:15.13', 'line', ['CVE-2025-1094'])]),
        ]);

        securityPage(securityPageMember($this->team), $database, 'database')
            ->assertSee('Update to 15.13 to fix it.')
            ->assertDontSeeHtml('updateImage(')
            ->assertDontSee('Scan now');
    });

    it('counts the findings that are not ignored in the All findings header', function () {
        $application = securityPageApplication();
        securityPageScan($application, 'nginx:1.27', [
            securityPageFinding('CVE-2026-0001'),
            securityPageFinding('CVE-2026-0002', ['fixedIn' => null, 'fixState' => 'not-fixed', 'package' => 'zlib']),
            securityPageFinding('CVE-2026-0003', ['severity' => 'low']),
        ]);

        securityPage($this->admin, $application, 'application')
            ->assertSee('All findings (3)')
            ->assertSeeText('Fixed by an update (2)')
            ->assertSeeText('No fix yet (1)')
            ->assertDontSee('zlib')
            ->call('setFixState', 'no_fix')
            ->assertSee('zlib');
    });
});

describe('rebuild', function () {
    it('queues the running commit without cache on the same server and destination', function () {
        $application = securityPageDockerfileApp();
        $deployer = securityPageMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $application->uuid, 'levels' => ['read', 'deploy']],
        ]);

        securityPage($deployer, $application, 'application')
            ->call('rebuild', '')
            ->assertDispatched('success', 'Rebuild started.');

        $queued = ApplicationDeploymentQueue::query()->where('application_id', $application->id)->latest('id')->first();
        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(2)
            ->and($queued->commit)->toBe('a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2')
            ->and((bool) $queued->force_rebuild)->toBeTrue()
            ->and((int) $queued->server_id)->toBe($this->server->id)
            ->and((int) $queued->destination_id)->toBe($this->destination->id)
            ->and(AuditEvent::query()->where('event', 'ui.image_scan.rebuild')->exists())->toBeTrue();
    });

    it('forbids a read-only member', function () {
        $application = securityPageDockerfileApp();

        securityPage(securityPageMember($this->team), $application, 'application')
            ->assertDontSeeHtml('submitAction: \'rebuild\'')
            ->call('rebuild', '')
            ->assertForbidden();

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
    });

    it('refuses Coolify\'s own app', function () {
        Team::forceCreate(['id' => 0, 'name' => 'Root team', 'personal_team' => false]);
        $this->admin->teams()->attach(0, ['role' => 'admin']);
        $application = securityPageApplication(['id' => 0, 'build_pack' => 'dockerfile', 'docker_registry_image_name' => null]);
        securityPageDeployment($application);

        securityPage($this->admin->fresh(), $application, 'application')
            ->call('rebuild', '')
            ->assertForbidden();

        expect(ApplicationDeploymentQueue::query()->where('application_id', 0)->count())->toBe(1);
    });

    it('refuses an app on a server that another Coolify instance manages now', function () {
        $application = securityPageDockerfileApp();
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        securityPage($this->admin, $application, 'application')
            ->assertDontSeeHtml('submitAction: \'rebuild\'')
            ->call('rebuild', '')
            ->assertDispatched('error', 'Another Coolify instance manages this server now.');

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
    });
});

describe('rebuild limits', function () {
    it('passes the image tag of the running deployment', function () {
        $application = securityPageDockerfileApp();
        SecurityCard::runningDeployment($application)->update(['docker_registry_image_tag' => 'v1.2']);

        securityPage($this->admin, $application, 'application')
            ->call('rebuild', '')
            ->assertDispatched('success', 'Rebuild started.');

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->latest('id')->first()->docker_registry_image_tag)->toBe('v1.2');
    });

    it('hides and refuses the rebuild of a Git app whose running commit is not known', function (?string $commit) {
        $application = securityPageDockerfileApp();
        SecurityCard::runningDeployment($application)->update(['commit' => $commit]);

        securityPage($this->admin, $application, 'application')
            ->assertSee('Rebuild without cache to get the fixed system packages.')
            ->assertDontSeeHtml('submitAction: \'rebuild\'')
            ->call('rebuild', '')
            ->assertDispatched('error', 'Coolify does not know which commit runs now. Redeploy the app first.');

        expect(ApplicationDeploymentQueue::query()->where('application_id', $application->id)->count())->toBe(1);
    })->with(['HEAD' => ['HEAD'], 'blank' => ['']]);

    it('hides the rebuild of Coolify\'s own app', function () {
        Team::forceCreate(['id' => 0, 'name' => 'Root team', 'personal_team' => false]);
        $this->admin->teams()->attach(0, ['role' => 'admin']);
        $application = securityPageApplication(['id' => 0, 'build_pack' => 'dockerfile', 'docker_registry_image_name' => null]);
        $deployment = securityPageDeployment($application);
        securityPageScan($application, $application->uuid.':'.$deployment->commit, [securityPageFinding('CVE-2026-2000', ['kev' => false, 'epss' => 0.5])], [
            'deployment_queue_id' => $deployment->id,
            'builds_image' => true,
        ]);

        securityPage($this->admin->fresh(), $application, 'application')
            ->assertSee('Rebuild without cache to get the fixed system packages.')
            ->assertDontSeeHtml('submitAction: \'rebuild\'');
    });

    it('authorizes the rebuild against the team of the app after the session switched teams', function () {
        $application = securityPageDockerfileApp();
        $deployer = securityPageMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $application->uuid, 'levels' => ['read', 'deploy']],
        ]);
        $ownTeam = Team::factory()->create();
        $deployer->teams()->attach($ownTeam, ['role' => 'owner']);

        $page = securityPage($deployer->fresh(), $application, 'application');
        session(['currentTeam' => $ownTeam]);
        $page->call('rebuild', '')->assertDispatched('success', 'Rebuild started.');

        expect(AuditEvent::query()->where('event', 'ui.image_scan.rebuild')->sole()->team_id)->toBe($this->team->id);
    });
});

describe('merged service update', function () {
    it('updates every image of the action and restarts the service once', function () {
        [$service, $main, $runners] = securityPageN8nService();
        RestartService::shouldRun()->once()->andReturn(new Activity);
        $key = SecurityCard::key(SecurityStatus::forResource($service)->actions[0]);

        securityPage($this->admin, $service, 'service')
            ->call('updateImages', $key, '')
            ->assertDispatched('success', 'Update started.');

        $compose = Yaml::parse($service->fresh()->docker_compose_raw);
        expect($main->fresh()->image)->toBe('n8nio/n8n:1.1')
            ->and($runners->fresh()->image)->toBe('n8nio/runners:1.1')
            ->and(data_get($compose, 'services.n8n.image'))->toBe('n8nio/n8n:1.1')
            ->and(data_get($compose, 'services.n8n runners.image'))->toBe('n8nio/runners:1.1');
    });

    it('puts every image back when the restart fails', function () {
        [$service, $main, $runners] = securityPageN8nService();
        $raw = $service->docker_compose_raw;
        RestartService::shouldRun()->once()->andThrow(new RuntimeException('ssh: connect to host failed'));
        $key = SecurityCard::key(SecurityStatus::forResource($service)->actions[0]);

        securityPage($this->admin, $service, 'service')
            ->call('updateImages', $key, '')
            ->assertDispatched('error', 'The restart failed. Coolify kept the old image.');

        expect($service->fresh()->docker_compose_raw)->toBe($raw)
            ->and($main->fresh()->image)->toBe('n8nio/n8n:1.0')
            ->and($runners->fresh()->image)->toBe('n8nio/runners:1.0');
    });

    it('refuses a part that is no longer a better candidate of its own scan', function () {
        [$service, $main, $runners] = securityPageN8nService();
        $key = SecurityCard::key(SecurityStatus::forResource($service)->actions[0]);
        RestartService::shouldRun()->never();
        // The runners image changed after the page loaded; its scan no longer offers 1.1.
        ResourceScans::currentFor($runners)->first()->update(['candidates' => securityPageCandidates([], 'no_better')]);

        securityPage($this->admin, $service, 'service')
            ->call('updateImages', $key, '')
            ->assertDispatched('error');

        expect($main->fresh()->image)->toBe('n8nio/n8n:1.0')
            ->and($runners->fresh()->image)->toBe('n8nio/runners:1.0');
    });

    it('forbids a read-only member', function () {
        [$service] = securityPageN8nService();
        $key = SecurityCard::key(SecurityStatus::forResource($service)->actions[0]);
        RestartService::shouldRun()->never();

        securityPage(securityPageMember($this->team), $service, 'service')
            ->call('updateImages', $key, '')
            ->assertForbidden();
    });
});

describe('update limits', function () {
    it('refuses a merged update on a server that another Coolify instance manages', function () {
        [$service, $main, $runners] = securityPageN8nService();
        $raw = $service->docker_compose_raw;
        $key = SecurityCard::key(SecurityStatus::forResource($service)->actions[0]);
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();
        Server::flushIdentityMap();
        RestartService::shouldRun()->never();

        securityPage($this->admin, $service, 'service')
            ->assertDontSeeHtml('updateImages(')
            ->call('updateImages', $key, '')
            ->assertDispatched('error');

        expect($service->fresh()->docker_compose_raw)->toBe($raw)
            ->and($main->fresh()->image)->toBe('n8nio/n8n:1.0')
            ->and($runners->fresh()->image)->toBe('n8nio/runners:1.0');
        expect(fn () => UpdateResourceImage::make()->handleParts($service->fresh(), [
            ['owner' => $main, 'ref' => 'n8nio/n8n:1.1'],
            ['owner' => $runners, 'ref' => 'n8nio/runners:1.1'],
        ], $this->admin))->toThrow(ImageUpdateRefused::class, 'Another Coolify instance manages this server now.');
        expect($main->fresh()->image)->toBe('n8nio/n8n:1.0');
    });

    it('refuses a single update on a server that another Coolify instance manages', function () {
        $database = securityPageDatabase();
        securityPageScan($database, 'postgres:15.0', [securityPageFinding('CVE-2025-1094')], [
            'candidates' => securityPageCandidates([securityPageEntry('postgres:15.13', 'line', ['CVE-2025-1094'])]),
        ]);
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();
        Server::flushIdentityMap();

        securityPage($this->admin, $database, 'database')
            ->call('updateImage', 'postgres:15.13', '', '')
            ->assertDispatched('error');

        expect(fn () => UpdateResourceImage::run($database->fresh(), 'postgres:15.13', $this->admin))
            ->toThrow(ImageUpdateRefused::class, 'Another Coolify instance manages this server now.');
        expect($database->fresh()->image)->toBe('postgres:15.0');
    });

    it('refuses a part with a ref that is not a better candidate of its own scan', function () {
        [$service, $main, $runners] = securityPageN8nService();
        RestartService::shouldRun()->never();

        expect(fn () => UpdateResourceImage::make()->handleParts($service, [
            ['owner' => $main, 'ref' => 'n8nio/n8n:1.1'],
            ['owner' => $runners, 'ref' => 'n8nio/runners:0.9'],
        ], $this->admin))->toThrow(ImageUpdateRefused::class);

        expect($main->fresh()->image)->toBe('n8nio/n8n:1.0')
            ->and($runners->fresh()->image)->toBe('n8nio/runners:1.0');
    });

    it('authorizes a merged update against the team of the service after the session switched teams', function () {
        [$service, $main] = securityPageN8nService();
        $writer = securityPageMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $service->uuid, 'levels' => ['read', 'write']],
        ]);
        $ownTeam = Team::factory()->create();
        $writer->teams()->attach($ownTeam, ['role' => 'owner']);
        RestartService::shouldRun()->once()->andReturn(new Activity);
        $key = SecurityCard::key(SecurityStatus::forResource($service)->actions[0]);

        $page = securityPage($writer->fresh(), $service, 'service');
        session(['currentTeam' => $ownTeam]);
        $page->call('updateImages', $key, '')->assertDispatched('success', 'Update started.');

        expect($main->fresh()->image)->toBe('n8nio/n8n:1.1')
            ->and(AuditEvent::query()->where('event', 'ui.image_scan.image_updated')->sole()->team_id)->toBe($this->team->id);
    });
});

describe('sidebar', function () {
    it('shows a red dot for update now and no count', function () {
        $application = securityPageApplication();
        securityPageScan($application, 'nginx:1.27', [securityPageFinding('CVE-2026-0001')], [
            'candidates' => securityPageCandidates([securityPageEntry('nginx:1.28', 'line', ['CVE-2026-0001'])]),
        ]);
        ResourceScans::refreshSummaries(ResourceScans::current($application));
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $this->blade('<x-application.configuration-sidebar :application="$application" current-route="project.application.configuration" />', [
            'application' => $application->fresh(),
        ])->assertSee('Security')
            ->assertSee('aria-label="Security: update now"', false)
            ->assertDontSee('fixable now')
            ->assertDontSee('Vulnerabilities');
    });

    it('shows a yellow dot for a security update of a database', function () {
        $database = securityPageDatabase();
        securityPageScan($database, 'postgres:15.0', [securityPageFinding('CVE-2025-1094', ['kev' => false])], [
            'candidates' => securityPageCandidates([securityPageEntry('postgres:15.13', 'line', ['CVE-2025-1094'])]),
        ]);
        ResourceScans::refreshSummaries(ResourceScans::current($database));
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $this->blade('<x-database.configuration-sidebar :database="$database" current-route="project.database.configuration" />', [
            'database' => $database->fresh(),
        ])->assertSee('aria-label="Security: security update"', false);
    });

    it('shows no dot when no action is needed', function () {
        $application = securityPageApplication();
        securityPageScan($application, 'nginx:1.27', []);
        ResourceScans::refreshSummaries(ResourceScans::current($application));
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $this->blade('<x-application.configuration-sidebar :application="$application" current-route="project.application.configuration" />', [
            'application' => $application->fresh(),
        ])->assertSee('Security')
            ->assertDontSee('aria-label="Security:', false);
    });
});

describe('deploy gate popover', function () {
    /**
     * A Docker image app whose deploy of nginx:1.27 was blocked; a newer 1.28 fixes the problem.
     *
     * @return array{0: Application, 1: ApplicationDeploymentQueue}
     */
    function securityPageBlockedDeploy(): array
    {
        $application = securityPageApplication();
        $deployment = securityPageDeployment($application, [
            'status' => ApplicationDeploymentStatus::FAILED->value,
            'scan_gate_result' => [
                'blocked' => true, 'scan_ran' => true, 'error' => null, 'count' => 1, 'top' => [],
                'headline' => 'Deploy blocked: this image has a security problem that attackers are using.',
            ],
        ]);
        securityPageScan($application, 'nginx:1.27', [securityPageFinding('CVE-2026-0001')], [
            'deployment_queue_id' => $deployment->id,
            'candidates' => securityPageCandidates([securityPageEntry('nginx:1.28', 'line', ['CVE-2026-0001'])]),
        ]);

        return [$application, $deployment];
    }

    it('shows the action that fixes the blocked image next to Deploy anyway', function () {
        [$application, $deployment] = securityPageBlockedDeploy();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Show::class, [
            'project_uuid' => $this->environment->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $application->uuid,
            'deployment_uuid' => $deployment->deployment_uuid,
        ])->assertSee('Update to 1.28.')
            ->assertSee('Open Security')
            ->assertSee('Deploy anyway');
    });

    it('reads the action stored with the gate result', function () {
        [$application, $deployment] = securityPageBlockedDeploy();
        $deployment->update(['scan_gate_result' => [...$deployment->scan_gate_result, 'action' => ['sentence' => 'Upgrade lodash to 4.17.21 or later, then redeploy.', 'button' => false]]]);

        expect(SecurityCard::gateAction($application, $deployment->fresh()))
            ->toBe(['sentence' => 'Upgrade lodash to 4.17.21 or later, then redeploy.', 'button' => false]);
    });

    it('computes the action of an older result once per version of the scan', function () {
        [$application, $deployment] = securityPageBlockedDeploy();

        expect(SecurityCard::gateAction($application, $deployment))->toBe(['sentence' => 'Update to 1.28.', 'button' => true]);

        ImageScan::query()->where('deployment_queue_id', $deployment->id)->toBase()->update(['findings' => json_encode([])]);
        expect(SecurityCard::gateAction($application, $deployment))->toBe(['sentence' => 'Update to 1.28.', 'button' => true]);

        ImageScan::query()->where('deployment_queue_id', $deployment->id)->toBase()->update(['candidates' => json_encode(securityPageCandidates([], 'no_better'))]);
        expect(SecurityCard::gateAction($application, $deployment))->toBeNull();
    });

    it('shows the action in the application heading', function () {
        [$application] = securityPageBlockedDeploy();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Heading::class, ['application' => $application])
            ->assertSee('Update to 1.28.')
            ->assertSee('Open Security');
    });
});

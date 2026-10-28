<?php

use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\RefreshImageScanSummariesJob;
use App\Jobs\ScanResourceImagesJob;
use App\Livewire\ImageScan\Packages;
use App\Livewire\ImageScan\Vulnerabilities;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ImageScan\ResourceScans;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Cache::flush();
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array']);
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->admin = vulnerabilitiesMember($this->team, 'admin');
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
    ]);
});

function vulnerabilitiesMember(Team $team, string $role = 'member', ?array $access = null): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function vulnerabilitiesFinding(array $overrides = []): array
{
    return [
        'id' => 'CVE-2024-0001',
        'aliases' => [],
        'severity' => 'high',
        'package' => 'openssl',
        'version' => '3.0.1',
        'type' => 'apk',
        'artifactId' => 'a1',
        'fixedIn' => '3.0.2',
        'fixState' => 'fixed',
        'kev' => false,
        'epss' => 0.2,
        'risk' => 1.0,
        'summary' => 'A bug.',
        'layer' => 'app',
        ...$overrides,
    ];
}

/**
 * A stored summary whose security status counts `$fixed` relevant findings that an action fixes.
 *
 * @return array<string, mixed>
 */
function vulnerabilitiesSummary(int $fixed, array $overrides = []): array
{
    return ['truncated' => false, 'eol_distro' => null, 'security' => ['facts' => ['counts' => ['relevant_fixed' => $fixed]]], ...$overrides];
}

/**
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function vulnerabilitiesScan(Model $resource, array $findings, array $attributes = []): ImageScan
{
    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => 'nginx:1.27',
        'builds_image' => true,
        'findings' => $findings,
        'summary' => ['truncated' => false, 'eol_distro' => null],
        'scanned_at' => now(),
        ...$attributes,
    ]);
}

function vulnerabilitiesPage(User $user, Model $resource, string $type = 'application', ?Team $sessionTeam = null): Testable
{
    test()->actingAs($user);
    session(['currentTeam' => $sessionTeam ?? test()->team]);

    return Livewire::test(Vulnerabilities::class, ['resourceType' => $type, 'resourceUuid' => $resource->uuid]);
}

function vulnerabilitiesService(): Service
{
    return Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

describe('authorization', function () {
    it('shows the findings to a member with read access', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        vulnerabilitiesPage(vulnerabilitiesMember($this->team), $this->application)
            ->assertOk()
            ->assertSee('openssl')
            ->assertSee('CVE-2024-0001')
            ->assertDontSee('Scan now');
    });

    it('does not let a member scan or ignore', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);
        $member = vulnerabilitiesMember($this->team);

        vulnerabilitiesPage($member, $this->application)->call('scanNow')->assertForbidden();
        vulnerabilitiesPage($member, $this->application)
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'resource', '90', 'Not reachable')
            ->assertForbidden();

        Queue::assertNotPushed(ScanResourceImagesJob::class);
        expect(ImageScanIgnore::query()->count())->toBe(0);
    });

    it('returns not found for a resource of another team', function () {
        $stranger = vulnerabilitiesMember(Team::factory()->create(), 'owner');

        vulnerabilitiesPage($stranger, $this->application, 'application', $stranger->teams->first())->assertNotFound();
    });
});

describe('scan now', function () {
    it('queues one scan of the running image and refuses a second one within a minute', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);
        $page = vulnerabilitiesPage($this->admin, $this->application);

        $page->call('scanNow')->assertDispatched('success', 'Scan started');
        $page->call('scanNow')->assertDispatched('error');

        Queue::assertPushed(ScanResourceImagesJob::class, 1);
        Queue::assertPushed(ScanResourceImagesJob::class, fn (ScanResourceImagesJob $job) => $job->resource->is($this->application)
            && $job->imageRefs === ['nginx:1.27']);
    });

    it('asks for a deploy when no image of the app is known', function () {
        $this->application->forceFill(['build_pack' => 'nixpacks', 'docker_registry_image_name' => null])->saveQuietly();

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('scanNow')
            ->assertDispatched('error', 'Deploy the app first.');

        Queue::assertNotPushed(ScanResourceImagesJob::class);
    });

    it('does not start a scan while one is running', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);
        Cache::put(ScanImage::progressCacheKey($this->application), ['run_id' => 'x', 'stage' => 'sbom', 'started_at' => now()->toIso8601String()]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('Find vulnerabilities')
            ->call('scanNow')
            ->assertDispatched('error');

        Queue::assertNotPushed(ScanResourceImagesJob::class);
    });
});

describe('scan progress', function () {
    it('shows each checkpoint in order for the stage of the scan', function (?string $stage, array $statuses) {
        Cache::put(ScanImage::progressCacheKey($this->application), array_filter([
            'run_id' => 'x',
            'stage' => $stage,
            'started_at' => now()->toIso8601String(),
        ]));

        $checkpoints = vulnerabilitiesPage($this->admin, $this->application)->viewData('checkpoints');

        expect(array_column($checkpoints, 'status', 'title'))->toBe(array_combine(
            ['Get scanner', 'Update database', 'Read image', 'Find vulnerabilities'],
            $statuses,
        ));
    })->with([
        'before the first stage' => [null, ['running', 'pending', 'pending', 'pending']],
        'database' => ['database', ['success', 'running', 'pending', 'pending']],
        'inspect' => ['inspect', ['success', 'success', 'running', 'pending']],
        'sbom' => ['sbom', ['success', 'success', 'running', 'pending']],
        'vulnerabilities' => ['vulnerabilities', ['success', 'success', 'success', 'running']],
    ]);
});

describe('ignore', function () {
    it('rejects an invalid vulnerability ID', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', 'CVE 2024 <x>', 'vulnerability', 'resource', '90', 'Not reachable')
            ->assertHasErrors('vulnerability_id');

        expect(ImageScanIgnore::query()->count())->toBe(0);
    });

    it('shows a validation error as a toast', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'resource', '90', '')
            ->assertHasErrors('reason')
            ->assertDispatched('error');
    });

    it('refreshes the counts of the other team resources after a team-wide ignore', function () {
        $other = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
            'build_pack' => 'dockerimage',
            'docker_registry_image_name' => 'nginx',
            'docker_registry_image_tag' => '1.27',
        ]);
        $critical = vulnerabilitiesFinding(['severity' => 'critical']);
        vulnerabilitiesScan($this->application, [$critical]);
        $otherScan = vulnerabilitiesScan($other, [$critical], ['summary' => vulnerabilitiesSummary(1)]);
        $otherTeamApp = Application::factory()->create([
            'environment_id' => Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => Team::factory()->create()->id])->id])->id,
            'destination_id' => $this->destination->id,
            'destination_type' => $this->destination->getMorphClass(),
        ]);
        $foreignScan = vulnerabilitiesScan($otherTeamApp, [$critical], ['summary' => vulnerabilitiesSummary(7)]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'team', '90', 'Not reachable');

        Queue::assertPushed(RefreshImageScanSummariesJob::class, fn (RefreshImageScanSummariesJob $job) => $job->teamId === $this->team->id);
        expect(ResourceScans::storedFixedCount($otherScan->fresh()))->toBe(1);

        (new RefreshImageScanSummariesJob($this->team->id))->handle();

        expect(ResourceScans::storedFixedCount($otherScan->fresh()))->toBe(0)
            ->and(ResourceScans::storedFixedCount($foreignScan->fresh()))->toBe(7);
    });

    it('rejects an ID that is not in the results', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', 'CVE-2099-9999', 'vulnerability', 'resource', '90', 'Not reachable')
            ->assertHasErrors('vulnerability_id');

        expect(ImageScanIgnore::query()->count())->toBe(0);
    });

    it('stores the ignore for the team of the resource after the session switched teams', function () {
        $otherTeam = Team::factory()->create();
        $this->admin->teams()->attach($otherTeam, ['role' => 'owner']);
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        $page = vulnerabilitiesPage($this->admin, $this->application);
        session(['currentTeam' => $otherTeam]);
        $page->call('ignore', 'CVE-2024-0001', 'vulnerability', 'resource', '90', 'Not reachable')
            ->assertHasNoErrors()
            ->assertDispatched('success', 'Ignored');

        $ignore = ImageScanIgnore::query()->sole();
        expect($ignore->team_id)->toBe($this->team->id)
            ->and($ignore->resource_type)->toBe($this->application->getMorphClass())
            ->and($ignore->resource_id)->toBe($this->application->id)
            ->and($ignore->vulnerability_id)->toBe('CVE-2024-0001')
            ->and($ignore->created_by)->toBe($this->admin->id)
            ->and($ignore->expires_at->isSameDay(now()->addDays(90)))->toBeTrue();
    });

    it('ignores a package for all team resources without an expiry', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding(['epss' => null])]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', 'openssl', 'package', 'team', 'never', 'Patched by the base image')
            ->assertHasNoErrors();

        $ignore = ImageScanIgnore::query()->sole();
        expect($ignore->package)->toBe('openssl')
            ->and($ignore->vulnerability_id)->toBeNull()
            ->and($ignore->resource_id)->toBeNull()
            ->and($ignore->expires_at)->toBeNull();
    });

    it('refuses an ignore without an expiry for a problem with an exploit signal', function (string $target, string $kind, array $finding) {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding(['epss' => null]), vulnerabilitiesFinding($finding)]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', $target, $kind, 'resource', 'never', 'Not reachable')
            ->assertHasErrors(['expires'])
            ->assertDispatched('error', 'Attackers are using or are likely to use this problem, so its ignore must expire. Choose 30 or 90 days.');
        expect(ImageScanIgnore::query()->count())->toBe(0);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', $target, $kind, 'resource', '90', 'Not reachable')
            ->assertHasNoErrors();
        expect(ImageScanIgnore::query()->sole()->expires_at->isSameDay(now()->addDays(90)))->toBeTrue();
    })->with([
        'exploited ID' => ['CVE-2026-0002', 'vulnerability', ['id' => 'CVE-2026-0002', 'kev' => true, 'epss' => null]],
        'exploited alias' => ['CVE-2026-0003', 'vulnerability', ['id' => 'GHSA-aaaa-bbbb-cccc', 'aliases' => ['CVE-2026-0003'], 'kev' => true, 'epss' => null]],
        'likely to be exploited' => ['CVE-2026-0004', 'vulnerability', ['id' => 'CVE-2026-0004', 'epss' => 0.5, 'cvssScore' => 9.8]],
        'package with an exploited problem' => ['zlib', 'package', ['id' => 'CVE-2026-0005', 'package' => 'zlib', 'kev' => true, 'epss' => null]],
    ]);

    it('offers "Never" only for problems without an exploit signal', function () {
        vulnerabilitiesScan($this->application, [
            vulnerabilitiesFinding(['epss' => null]),
            vulnerabilitiesFinding(['id' => 'CVE-2026-0002', 'package' => 'zlib', 'kev' => true, 'epss' => null]),
        ]);

        $html = preg_replace('/\s+/', ' ', vulnerabilitiesPage($this->admin, $this->application)->html());

        expect($html)->toContain("target: 'CVE-2026-0002', kind: 'vulnerability', owner: '{$this->application->uuid}', neverAllowed: false,")
            ->and($html)->toContain("target: 'zlib', kind: 'package', owner: '{$this->application->uuid}', neverAllowed: false,")
            ->and($html)->toContain("target: 'CVE-2024-0001', kind: 'vulnerability', owner: '{$this->application->uuid}', neverAllowed: true,")
            ->and($html)->toContain("target: 'openssl', kind: 'package', owner: '{$this->application->uuid}', neverAllowed: true,");
    });

    it('lets only team admins ignore for all team resources', function () {
        $writer = vulnerabilitiesMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'write']],
        ]);
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        vulnerabilitiesPage($writer, $this->application)
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'team', '30', 'Not reachable')
            ->assertForbidden();
        vulnerabilitiesPage($writer, $this->application)
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'resource', '30', 'Not reachable')
            ->assertHasNoErrors();

        expect(ImageScanIgnore::query()->sole()->resource_id)->toBe($this->application->id);
    });

    it('updates the stored count of the scan', function () {
        $scan = vulnerabilitiesScan($this->application, [vulnerabilitiesFinding(['severity' => 'critical', 'type' => 'npm'])], [
            'summary' => vulnerabilitiesSummary(1),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'resource', '90', 'Not reachable');

        expect(ResourceScans::storedFixedCount($scan->fresh()))->toBe(0)
            ->and($scan->fresh()->summary['ignored'])->toBe(1);
    });
});

describe('findings', function () {
    it('shows the findings with a fixed version by default', function () {
        vulnerabilitiesScan($this->application, [
            vulnerabilitiesFinding(),
            vulnerabilitiesFinding(['id' => 'CVE-2024-0002', 'package' => 'zlib', 'fixedIn' => null, 'fixState' => 'not-fixed']),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('openssl')
            ->assertDontSee('zlib')
            ->call('setFixState', 'no_fix')
            ->assertSee('zlib')
            ->assertDontSee('openssl');
    });

    it('finds a vulnerability by an alias', function () {
        vulnerabilitiesScan($this->application, [
            vulnerabilitiesFinding(['id' => 'CVE-2024-1111', 'aliases' => ['GHSA-abcd-efgh-ijkl'], 'package' => 'lodash']),
            vulnerabilitiesFinding(['id' => 'CVE-2024-2222', 'package' => 'express']),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->set('search', 'ghsa-abcd')
            ->assertSee('lodash')
            ->assertDontSee('express');
    });

    it('links only CVE and GHSA IDs that pass the ID pattern', function () {
        vulnerabilitiesScan($this->application, [
            vulnerabilitiesFinding(['id' => 'CVE-2024-0001', 'aliases' => ['GHSA-aaaa-bbbb-cccc', 'bad alias<script>']]),
            vulnerabilitiesFinding(['id' => 'ALAS-2024-1', 'package' => 'curl']),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->set('view', 'cve')
            ->assertSeeHtml('href="https://nvd.nist.gov/vuln/detail/CVE-2024-0001"')
            ->assertSeeHtml('href="https://github.com/advisories/GHSA-aaaa-bbbb-cccc"')
            ->assertSee('ALAS-2024-1')
            ->assertDontSeeHtml('>ALAS-2024-1</a>')
            ->assertDontSee('bad alias');
    });

    it('says when no finding has a fixed version', function () {
        vulnerabilitiesScan($this->application, [
            vulnerabilitiesFinding(['package' => 'zlib', 'fixedIn' => null, 'fixState' => 'not-fixed']),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('No finding has a fixed version.')
            ->assertSee('No fix yet (1)');
    });

    it('hides ignored findings and counts them', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);
        ImageScanIgnore::factory()->create([
            'team_id' => $this->team->id,
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'vulnerability_id' => 'CVE-2024-0001',
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('All findings (0)')
            ->assertSee('1 ignored')
            ->set('showIgnored', true)
            ->assertSee('CVE-2024-0001');
    });

    it('does not show preview scans', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => (string) Str::uuid(),
            'application_id' => $this->application->id,
            'pull_request_id' => 7,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'server_id' => $this->server->id,
        ]);
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding(['package' => 'preview-only'])], ['deployment_queue_id' => $deployment->id]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('Not scanned yet. The next deploy scans the image.')
            ->assertDontSee('preview-only');
    });
});

describe('states', function () {
    it('shows the off state with a link for admins', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee("Image scanning is off for {$this->server->name}.")
            ->assertSee('Turn on');
    });

    it('asks members to ask an admin when scanning is off', function () {
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

        vulnerabilitiesPage(vulnerabilitiesMember($this->team), $this->application)
            ->assertSee("Image scanning is off for {$this->server->name}.")
            ->assertSee('Ask a team admin to turn it on.')
            ->assertDontSee('Turn on<');
    });

    it('shows the not scanned state', function () {
        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('Not scanned yet. The next deploy scans the image.');
    });

    it('shows the message of the last scan error', function () {
        Cache::put(ScanImage::lastErrorCacheKey($this->application), [
            'code' => 'disk_space',
            'context' => ['server' => 'prod-1', 'n' => 3],
            'at' => now()->toIso8601String(),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('Not enough disk space on prod-1: 3 GB needed.');
    });

    it('asks for a new check of cut results, and names an end-of-life distro', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()], [
            'scanned_at' => now()->subDays(5)->subHour(),
            'summary' => vulnerabilitiesSummary(1, ['truncated' => true, 'eol_distro' => ['name' => 'alpine', 'version' => '3.12']]),
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSeeText('nginx:1.27 needs another check: the scan result was too large to read completely.')
            ->assertSee('This image uses Alpine 3.12, which no longer gets security fixes.')
            ->assertSee('Only part of the results is shown, with the problems that attackers are using first.');
    });

    it('says why no newer version is offered when the registry needs a login, with a link for server admins', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding(['kev' => true])], [
            'builds_image' => false,
            'candidates' => ['status' => 'auth_required', 'error' => 'registry_auth', 'entries' => [], 'checked_at' => now()->toIso8601String()],
        ]);
        $text = "Coolify cannot read the tags of nginx. Log in to docker.io on {$this->server->name}.";
        $link = route('server.registries', ['server_uuid' => $this->server->uuid]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee('No fix yet')
            ->assertSee($text)
            ->assertSeeHtml('href="'.$link.'"');

        vulnerabilitiesPage(vulnerabilitiesMember($this->team), $this->application)
            ->assertSee($text)
            ->assertDontSeeHtml('href="'.$link.'"');
    });

    it('says when the daily limit of version checks was reached', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding(['kev' => true])], [
            'builds_image' => false,
            'candidates' => ['status' => 'failed', 'error' => 'daily_limit', 'entries' => [], 'checked_at' => now()->toIso8601String()],
        ]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSee("Coolify reached the daily limit of version checks on {$this->server->name}. It checks again tomorrow.")
            ->assertDontSee('Registry logins');
    });

    it('says when the running image is not the scanned image', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);
        $this->application->forceFill(['docker_registry_image_tag' => '1.28'])->saveQuietly();

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertSeeText('nginx:1.27 needs another check: the running image is not the checked image.');
    });

    it('does not warn while the scanned image runs', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()]);

        vulnerabilitiesPage($this->admin, $this->application)
            ->assertDontSee('the running image is not the checked image');
    });
});

describe('service page', function () {
    it('shows the findings of every application and database and ignores for the one that has it', function () {
        $service = vulnerabilitiesService();
        $web = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:1.27']);
        $db = ServiceDatabase::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'db', 'image' => 'postgres:16']);
        vulnerabilitiesScan($web, [vulnerabilitiesFinding(['package' => 'nginx-module'])]);
        vulnerabilitiesScan($db, [vulnerabilitiesFinding(['id' => 'CVE-2024-5555', 'package' => 'libpq'])], ['image_ref' => 'postgres:16']);

        $page = vulnerabilitiesPage($this->admin, $service, 'service')
            ->assertSee('nginx-module')
            ->assertSee('libpq')
            ->assertSee('postgres:16');

        $page->call('ignore', 'CVE-2024-5555', 'vulnerability', 'resource', '30', 'Internal only', $db->uuid)
            ->assertHasNoErrors();

        $ignore = ImageScanIgnore::query()->sole();
        expect($ignore->resource_type)->toBe($db->getMorphClass())
            ->and($ignore->resource_id)->toBe($db->id)
            ->and($ignore->team_id)->toBe($this->team->id);
    });

    it('rejects an owner from another service', function () {
        $service = vulnerabilitiesService();
        $web = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:1.27']);
        vulnerabilitiesScan($web, [vulnerabilitiesFinding()]);
        $otherService = vulnerabilitiesService();
        $foreign = ServiceDatabase::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $otherService->id, 'name' => 'db', 'image' => 'postgres:16']);
        vulnerabilitiesScan($foreign, [vulnerabilitiesFinding()], ['image_ref' => 'postgres:16']);

        vulnerabilitiesPage($this->admin, $service, 'service')
            ->call('ignore', 'CVE-2024-0001', 'vulnerability', 'resource', '30', 'Internal only', $foreign->uuid)
            ->assertNotFound();

        expect(ImageScanIgnore::query()->count())->toBe(0);
    });

    it('filters by image', function () {
        $service = vulnerabilitiesService();
        $web = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:1.27']);
        $db = ServiceDatabase::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'db', 'image' => 'postgres:16']);
        vulnerabilitiesScan($web, [vulnerabilitiesFinding(['package' => 'nginx-module'])]);
        vulnerabilitiesScan($db, [vulnerabilitiesFinding(['package' => 'libpq'])], ['image_ref' => 'postgres:16']);

        vulnerabilitiesPage($this->admin, $service, 'service')
            ->call('toggleImage', 'postgres:16')
            ->assertSee('libpq')
            ->assertDontSee('nginx-module');
    });
});

describe('database page', function () {
    it('shows the findings of a standalone database', function () {
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        vulnerabilitiesScan($database, [vulnerabilitiesFinding(['package' => 'libpq'])], [
            'image_ref' => 'postgres:16-alpine',
            'builds_image' => false,
            'candidates' => [['ref' => 'postgres:16.4-alpine', 'kind' => 'line', 'better' => true, 'fixed_ids' => ['CVE-2024-0001']]],
            'summary' => vulnerabilitiesSummary(4),
        ]);

        vulnerabilitiesPage($this->admin, $database, 'database')
            ->assertSee('libpq')
            ->assertDontSee('the running image is not the checked image');
    });
});

describe('routes', function () {
    it('opens the Security page of an application, a service, and a database', function () {
        $service = vulnerabilitiesService();
        $database = create_standalone_postgresql($this->environment->id, $this->destination);
        $parameters = ['project_uuid' => $this->environment->project->uuid, 'environment_uuid' => $this->environment->uuid];
        $this->actingAs($this->admin)->withSession(['currentTeam' => $this->team]);

        $this->get(route('project.application.vulnerabilities', [...$parameters, 'application_uuid' => $this->application->uuid]))
            ->assertOk()->assertSee('Not scanned yet. The next deploy scans the image.');
        $this->get(route('project.service.vulnerabilities', [...$parameters, 'service_uuid' => $service->uuid]))
            ->assertOk()->assertSee('Not scanned yet. The next deploy scans the image.');
        $this->get(route('project.database.vulnerabilities', [...$parameters, 'database_uuid' => $database->uuid]))
            ->assertOk()->assertSee('Not scanned yet. The next deploy scans the image.');
    });
});

describe('packages', function () {
    it('searches and filters the packages of the current scan', function () {
        $sbom = ImageSbom::factory()->create([
            'server_id' => $this->server->id,
            'packages' => [
                ['id' => 'p1', 'name' => 'openssl', 'version' => '3.0.1', 'type' => 'apk', 'licenses' => [], 'purl' => '', 'layerId' => ''],
                ['id' => 'p2', 'name' => 'left-pad', 'version' => '1.3.0', 'type' => 'npm', 'licenses' => [], 'purl' => '', 'layerId' => ''],
                ['id' => 'p3', 'name' => 'busybox', 'version' => '1.36', 'type' => 'apk', 'licenses' => [], 'purl' => '', 'layerId' => ''],
            ],
        ]);
        vulnerabilitiesScan($this->application, [], ['image_sbom_id' => $sbom->id]);
        $this->actingAs(vulnerabilitiesMember($this->team));
        session(['currentTeam' => $this->team]);

        Livewire::test(Packages::class, ['resourceType' => 'application', 'resourceUuid' => $this->application->uuid])
            ->assertSee('Packages and SBOM')
            ->assertSee('Download SBOM')
            ->call('toggleType', 'apk')
            ->assertSee('busybox')
            ->assertDontSee('left-pad')
            ->set('search', 'open')
            ->assertSee('openssl')
            ->assertDontSee('busybox');
    });
});

describe('sidebar', function () {
    it('names the item Security and shows no count', function () {
        vulnerabilitiesScan($this->application, [vulnerabilitiesFinding()], [
            'summary' => vulnerabilitiesSummary(3),
        ]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $this->blade('<x-application.configuration-sidebar :application="$application" current-route="project.application.configuration" />', [
            'application' => $this->application->fresh(),
        ])->assertSee('menu-item-label">Security<', false)
            ->assertDontSee('fixable now')
            ->assertDontSee('Vulnerabilities');
    });

    it('shows the dot of the combined status of the service applications and databases', function () {
        $service = vulnerabilitiesService();
        $web = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'web', 'image' => 'nginx:1.27']);
        $db = ServiceDatabase::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => 'db', 'image' => 'postgres:16']);
        vulnerabilitiesScan($web, [], ['builds_image' => false, 'grype_db_built_at' => now()]);
        vulnerabilitiesScan($db, [vulnerabilitiesFinding(['kev' => true])], [
            'image_ref' => 'postgres:16',
            'builds_image' => false,
            'grype_db_built_at' => now(),
            'candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [
                ['ref' => 'postgres:16.4', 'kind' => 'line', 'better' => true, 'fixed_ids' => ['CVE-2024-0001']],
            ]],
        ]);
        ResourceScans::refreshSummaries(ResourceScans::current($service));
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $this->blade('<x-service.configuration-sidebar :service="$service" current-route="project.service.configuration" />', [
            'service' => $service->fresh(),
        ])->assertSee('aria-label="Security: update now"', false);
    });

    it('hides the item when the instance does not allow image scanning', function () {
        InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false]);
        Once::flush();
        Cache::flush();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $this->blade('<x-application.configuration-sidebar :application="$application" current-route="project.application.configuration" />', [
            'application' => $this->application->fresh(),
        ])->assertDontSee('menu-item-label">Security<', false);
    });
});

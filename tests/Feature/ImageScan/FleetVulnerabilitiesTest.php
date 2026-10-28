<?php

use App\Livewire\Dashboard;
use App\Livewire\GlobalSearch;
use App\Livewire\ImageScan\FleetVulnerabilities;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use App\Services\EnvironmentCanvas;
use App\Services\ImageScan\ImageScanPolicyResolver;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\TeamVulnerabilities;
use App\Services\ImageScan\VulnerabilityMarkers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->admin = fleetMember($this->team, 'admin');
    $this->server = fleetServer($this->team, 'scanning-server');
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->environment = Environment::query()->firstOrCreate(['project_id' => $this->project->id, 'name' => 'production']);
});

function fleetMember(Team $team, string $role = 'member', ?array $access = null): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user->fresh();
}

function fleetServer(Team $team, string $name, bool $scanning = true): Server
{
    $server = Server::factory()->create(['team_id' => $team->id, 'name' => $name]);
    $server->settings->forceFill(['is_image_scan_enabled' => $scanning])->saveQuietly();

    return $server;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function fleetApplication(string $name, ?Environment $environment = null, ?StandaloneDocker $destination = null, array $attributes = []): Application
{
    $destination ??= test()->destination;

    return Application::factory()->create([
        'name' => $name,
        'environment_id' => ($environment ?? test()->environment)->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fleetFinding(array $overrides = []): array
{
    return [
        'id' => 'CVE-2024-0001', 'aliases' => [], 'severity' => 'high', 'package' => 'openssl', 'version' => '3.0.1',
        'type' => 'apk', 'artifactId' => 'a1', 'fixedIn' => '3.0.2', 'fixState' => 'fixed', 'kev' => false,
        'epss' => 0.2, 'risk' => 1.0, 'summary' => 'A bug.', 'layer' => 'app',
        ...$overrides,
    ];
}

/**
 * A completed scan of a third-party image with the stored security status of `$status` (update_now, update_available,
 * no_fix, check_needed, ok). An update fixes the findings of update_now and update_available: the same image with the
 * tag of `update` (default 1.28).
 *
 * @param  array<string, mixed>  $attributes  Scan attributes, plus `server` and `update`.
 */
function fleetScan(Model $resource, string $status = 'ok', array $attributes = []): ImageScan
{
    $server = $attributes['server'] ?? test()->server;
    $imageRef = $attributes['image_ref'] ?? 'nginx:1.27';
    $update = str($imageRef)->beforeLast(':').':'.($attributes['update'] ?? '1.28');
    unset($attributes['server'], $attributes['update']);
    $fixes = in_array($status, ['update_now', 'update_available'], true);

    $scan = ImageScan::factory()->create([
        'server_id' => $server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => $imageRef,
        'builds_image' => false,
        'findings' => match ($status) {
            'update_now' => [fleetFinding(['kev' => true])],
            'update_available' => [fleetFinding(['epss' => null])],
            'no_fix' => [fleetFinding(['kev' => true, 'fixedIn' => null, 'fixState' => 'not-fixed'])],
            default => [],
        },
        'candidates' => [
            'status' => $fixes ? 'ok' : 'no_better', 'checked_at' => now()->toIso8601String(), 'error' => null, 'tags_truncated' => false,
            'entries' => $fixes ? [['kind' => 'line', 'ref' => $update, 'better' => true, 'fixed_ids' => ['CVE-2024-0001'], 'eol' => null]] : [],
        ],
        'summary' => ['truncated' => false, 'eol_distro' => null],
        'grype_db_built_at' => $status === 'check_needed' ? now()->subDays(6) : now()->subDay(),
        'scanned_at' => now(),
        ...$attributes,
    ]);
    $scan->setRelation('resource', $resource);
    ResourceScans::refreshSummaries(collect([$scan]));

    return $scan->fresh();
}

/**
 * Livewire embeds list data with @js(), which writes quotes as \u0022.
 */
function fleetJsPayload(string $json): string
{
    return str_replace('"', '\u0022', $json);
}

function fleetPage(User $user, ?Team $team = null, array $query = []): Testable
{
    test()->actingAs($user);
    session(['currentTeam' => $team ?? test()->team]);

    return Livewire::withQueryParams($query)->test(FleetVulnerabilities::class);
}

describe('page', function () {
    it('opens for a member as the Security page and sorts by status, then name', function () {
        fleetScan(fleetApplication('b-ok-app'), 'ok');
        fleetScan(fleetApplication('a-ok-app'), 'ok');
        fleetApplication('never-app');
        fleetScan(fleetApplication('check-app'), 'check_needed');
        fleetScan(fleetApplication('nofix-app'), 'no_fix');
        fleetScan(fleetApplication('available-app'), 'update_available');
        fleetScan(fleetApplication('now-app'), 'update_now');

        $this->actingAs(fleetMember($this->team));
        session(['currentTeam' => $this->team]);
        $this->get(route('vulnerabilities'))
            ->assertOk()
            ->assertSee('Security | Coolify', false)
            ->assertSeeInOrder([
                'now-app', 'Update now', 'available-app', 'Security update', 'nofix-app', 'No fix yet',
                'check-app', 'Check needed', 'a-ok-app', 'No action needed', 'b-ok-app', 'No action needed',
                'never-app', 'Not checked',
            ]);
    });

    it('shows the primary action as a link to the Security card of the resource, without buttons', function () {
        $now = fleetApplication('now-app');
        fleetScan($now, 'update_now');
        $noFix = fleetApplication('nofix-app');
        fleetScan($noFix, 'no_fix');

        $page = fleetPage($this->admin)
            ->assertSee('Update to 1.28.')
            ->assertSeeHtml('href="'.TeamVulnerabilities::vulnerabilitiesUrl($now).'#security-section"')
            ->assertDontSeeHtml('<button type="button" class="button');

        expect(substr_count($page->html(), '#security-section'))->toBe(1);
    });

    it('reads only the stored status, not the findings', function () {
        $application = fleetApplication('now-app');
        $scan = fleetScan($application, 'update_now');
        $scan->forceFill(['findings' => []])->saveQuietly();

        fleetPage($this->admin)->assertSee('Update now')->assertSee('Update to 1.28.');
    });

    it('filters by status', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');
        fleetScan(fleetApplication('available-app'), 'update_available');
        fleetScan(fleetApplication('nofix-app'), 'no_fix');
        fleetApplication('never-app');

        fleetPage($this->admin)
            ->call('toggleStatus', 'update_now')
            ->assertSee('now-app')
            ->assertDontSee('available-app')
            ->assertDontSee('nofix-app')
            ->call('toggleStatus', 'no_fix')
            ->assertSeeInOrder(['now-app', 'nofix-app'])
            ->assertDontSee('available-app')
            ->call('toggleStatus', 'update_now')
            ->assertDontSee('now-app')
            ->call('toggleStatus', 'not-a-status')
            ->assertSet('statuses', ['no_fix'])
            ->call('resetFilters')
            ->assertSee('never-app');
    });

    it('opens filtered by the statuses in the URL and counts each status in the filter', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');
        fleetScan(fleetApplication('other-now-app'), 'update_now');
        fleetScan(fleetApplication('available-app'), 'update_available');

        fleetPage($this->admin, query: ['statuses' => ['update_now']])
            ->assertSet('statuses', ['update_now'])
            ->assertSet('perPage', 25)
            ->assertSee('now-app')
            ->assertDontSee('available-app')
            ->assertSee('Update now (2)')
            ->assertSee('Security update (1)')
            ->assertSee('Not checked (0)');
    });

    it('ignores malformed status filters in the URL', function (mixed $statuses, array $expected) {
        fleetScan(fleetApplication('now-app'), 'update_now');
        fleetScan(fleetApplication('available-app'), 'update_available');

        $page = fleetPage($this->admin, query: ['statuses' => $statuses])
            ->assertOk()
            ->assertSet('statuses', $expected)
            ->assertSee('now-app');

        if ($expected === []) {
            $page->assertSee('available-app');
        }
    })->with([
        'a string' => ['foo', []],
        'a number' => ['5', []],
        'nested arrays' => [[['x']], []],
        'unknown and nested values next to a status' => [['update_now', ['x'], 'foo'], ['update_now']],
    ]);

    it('ignores malformed server and project filters sent with an update', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');

        fleetPage($this->admin)
            ->set('servers', 'not-a-list')
            ->assertOk()
            ->assertSet('servers', [])
            ->set('projects', [['nested'], 7])
            ->assertOk()
            ->assertSet('projects', [])
            ->call('toggleServer', $this->server->uuid)
            ->assertOk()
            ->assertSee('now-app');
    });

    it('explains a check needed and a resource that is not checked in the Action column', function () {
        $check = fleetApplication('check-app');
        fleetScan($check, 'check_needed');
        $offServer = fleetServer($this->team, 'off-server', scanning: false);
        fleetApplication('off-app', destination: StandaloneDocker::query()->where('server_id', $offServer->id)->firstOrFail());

        fleetPage($this->admin)
            ->assertSee('Check again: the vulnerability database is out of date.')
            ->assertSeeHtml('href="'.TeamVulnerabilities::vulnerabilitiesUrl($check).'#security-section"')
            ->assertSee('Image scanning is off for this server.')
            ->assertSeeInOrder(['Check needed', 'Check again:', 'off-app', 'Not checked', 'Image scanning is off for this server.']);
    });

    it('shows the status next to the resource name in search mode', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');

        fleetPage($this->admin)
            ->set('search', 'openssl')
            ->assertSeeInOrder(['now-app', 'Update now', 'CVE-2024-0001', 'Fixed by an update']);
    });

    it('never lists resources of another team', function () {
        fleetScan(fleetApplication('own-app'), 'update_available');

        $otherTeam = Team::factory()->create();
        $otherServer = fleetServer($otherTeam, 'other-server');
        $otherEnvironment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $otherTeam->id])->id]);
        $foreign = fleetApplication('foreign-app', $otherEnvironment, StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail());
        fleetScan($foreign, 'update_now', ['server' => $otherServer, 'findings' => [fleetFinding(['id' => 'CVE-2021-44228'])]]);

        // A member of both teams, with the session on this team.
        $user = fleetMember($this->team);
        $otherTeam->members()->attach($user->id, ['role' => 'owner']);

        fleetPage($user->fresh())
            ->assertSee('own-app')
            ->assertDontSee('foreign-app')
            ->assertDontSee('other-server')
            ->set('search', 'CVE-2021-44228')
            ->assertDontSee('foreign-app');
    });

    it('shows a member with access to one project only the resources of that project', function () {
        fleetScan(fleetApplication('visible-app'), 'update_available');
        $hiddenEnvironment = Environment::factory()->create([
            'project_id' => Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Secret project'])->id,
        ]);
        fleetScan(fleetApplication('hidden-app', $hiddenEnvironment), 'update_now');
        fleetApplication('unscanned-hidden-app', $hiddenEnvironment);

        $member = fleetMember($this->team, 'member', [['scope' => 'project', 'uuid' => $this->project->uuid, 'levels' => ['read']]]);

        $this->actingAs($member);
        session(['currentTeam' => $this->team]);
        $this->get(route('vulnerabilities'))->assertOk();

        fleetPage($member)
            ->assertSee('visible-app')
            ->assertDontSee('hidden-app')
            ->assertDontSee('Secret project')
            ->assertDontSee('not scanned, so Coolify cannot tell.');
    });

    it('finds the resource whose finding has the searched ID as an alias', function () {
        $log4j = fleetApplication('log4j-app');
        fleetScan($log4j, 'update_now', ['findings' => [
            fleetFinding(['id' => 'GHSA-jfh8-c2jp-5v3q', 'aliases' => ['CVE-2021-44228'], 'severity' => 'critical', 'package' => 'log4j-core', 'version' => '2.14.1', 'fixedIn' => '2.15.0', 'type' => 'java-archive']),
        ]]);
        fleetScan(fleetApplication('other-app'), 'update_available');

        fleetPage($this->admin, query: ['search' => 'CVE-2021-44228'])
            ->assertSee('log4j-app')
            ->assertSee('GHSA-jfh8-c2jp-5v3q')
            ->assertSee('Fixed by an update')
            ->assertDontSee('other-app');
    });

    it('shows the state of each match, also when the finding is ignored', function () {
        $ignored = fleetApplication('ignored-app');
        fleetScan($ignored, 'ok', ['findings' => [fleetFinding(['id' => 'CVE-2024-9999'])]]);
        ImageScanIgnore::query()->create([
            'team_id' => $this->team->id, 'resource_type' => $ignored->getMorphClass(), 'resource_id' => $ignored->id,
            'vulnerability_id' => 'CVE-2024-9999', 'reason' => 'Not reachable', 'created_by' => $this->admin->id,
        ]);
        fleetScan(fleetApplication('waiting-app'), 'ok', ['findings' => [fleetFinding(['id' => 'CVE-2024-9999', 'fixedIn' => null, 'fixState' => 'not-fixed'])]]);
        fleetScan(fleetApplication('fixed-app'), 'ok', ['findings' => [fleetFinding(['id' => 'CVE-2024-9999'])]]);

        fleetPage($this->admin)
            ->set('search', 'cve-2024-9999')
            ->assertSeeInOrder(['fixed-app', 'Fixed by an update', 'waiting-app', 'No fix yet', 'ignored-app', 'Ignored']);
    });

    it('counts resources that were not scanned, also during a search', function () {
        fleetScan(fleetApplication('scanned-app'), 'update_available');
        fleetApplication('never-scanned-app');
        $offServer = fleetServer($this->team, 'off-server', scanning: false);
        $offApp = fleetApplication('off-app', destination: StandaloneDocker::query()->where('server_id', $offServer->id)->firstOrFail());
        fleetScan($offApp, 'update_now', ['server' => $offServer]);

        fleetPage($this->admin)
            ->assertSee('2 resources on 2 servers were not scanned, so Coolify cannot tell.')
            ->assertSeeInOrder(['scanned-app', 'Security update', 'off-app', 'Not checked'])
            ->assertDontSeeHtml('<span class="truncate">Update now</span>')
            ->set('search', 'openssl')
            ->assertSee('scanned-app')
            ->assertDontSee('off-app')
            ->assertSee('2 resources on 2 servers were not scanned, so Coolify cannot tell.');
    });

    it('hides the not scanned line when every resource was scanned', function () {
        fleetScan(fleetApplication('scanned-app'), 'update_available');

        fleetPage($this->admin)->assertDontSee('not scanned, so Coolify cannot tell.');
    });

    it('filters by server and project', function () {
        fleetScan(fleetApplication('shop-app'), 'update_available');
        $otherProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);
        $otherServer = fleetServer($this->team, 'blog-server');
        $blog = fleetApplication('blog-app', Environment::factory()->create(['project_id' => $otherProject->id]), StandaloneDocker::query()->where('server_id', $otherServer->id)->firstOrFail());
        fleetScan($blog, 'update_available', ['server' => $otherServer]);

        fleetPage($this->admin)
            ->call('toggleProject', $this->project->uuid)
            ->assertSee('shop-app')
            ->assertDontSee('blog-app')
            ->call('resetFilters')
            ->call('toggleServer', $otherServer->uuid)
            ->assertSee('blog-app')
            ->assertDontSee('shop-app')
            ->call('toggleServer', 'not-a-server')
            ->assertSee('blog-app');
    });

    it('shows the servers that do not scan to admins only', function () {
        fleetServer($this->team, 'off-server', scanning: false);

        fleetPage($this->admin)->assertSee('1 server does not scan images.')->assertSee('Go to servers');
        fleetPage(fleetMember($this->team))->assertDontSee('does not scan images.');
    });

    it('keys the dismissed servers callout by the number of servers', function () {
        fleetServer($this->team, 'off-1', scanning: false);
        fleetServer($this->team, 'off-2', scanning: false);

        fleetPage($this->admin)
            ->assertSee('2 servers do not scan images.')
            ->assertSeeHtml('coolify.image-scan.servers-not-scanning-dismissed.'.$this->team->id.'.2');
    });

    it('shows the off state when the instance does not allow scanning', function () {
        fleetScan(fleetApplication('scanned-app'), 'update_available');
        InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false]);
        Once::flush();

        fleetPage($this->admin)
            ->assertSee('Image scanning is off for this Coolify instance.')
            ->assertDontSee('scanned-app');
    });

    it('never shows a server that runs only resources a limited member cannot see', function () {
        $visible = fleetApplication('visible-app');
        fleetScan($visible, 'update_available', ['findings' => [fleetFinding(['package' => 'shared-lib'])]]);
        $hiddenServer = fleetServer($this->team, 'hidden-server');
        $hiddenEnvironment = Environment::factory()->create([
            'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
        ]);
        $hiddenDestination = StandaloneDocker::query()->where('server_id', $hiddenServer->id)->firstOrFail();
        $hidden = fleetApplication('hidden-app', $hiddenEnvironment, $hiddenDestination);
        fleetScan($hidden, 'update_now', ['server' => $hiddenServer, 'findings' => [fleetFinding(['package' => 'shared-lib'])]]);
        fleetApplication('unscanned-hidden-app', $hiddenEnvironment, $hiddenDestination);

        $member = fleetMember($this->team, 'member', [['scope' => 'project', 'uuid' => $this->project->uuid, 'levels' => ['read']]]);

        fleetPage($member)
            ->assertSee('scanning-server')
            ->assertDontSee('hidden-server')
            ->assertDontSee('not scanned, so Coolify cannot tell.')
            ->set('search', 'shared-lib')
            ->assertSee('visible-app')
            ->assertDontSee('hidden-app')
            ->assertDontSee('hidden-server');
    });

    it('searches package names with slashes and at signs, and escapes LIKE wildcards', function () {
        fleetScan(fleetApplication('go-app'), 'ok', ['findings' => [fleetFinding(['package' => 'golang.org/x/net', 'type' => 'go-module'])]]);
        fleetScan(fleetApplication('node-app'), 'ok', ['findings' => [fleetFinding(['package' => '@babel/traverse', 'type' => 'npm'])]]);

        fleetPage($this->admin)
            ->set('search', 'Golang.org/x/net')
            ->assertSee('go-app')
            ->assertDontSee('node-app')
            ->set('search', '@babel/traverse')
            ->assertSee('node-app')
            ->assertDontSee('go-app')
            ->set('search', '%')
            ->assertDontSee('go-app')
            ->assertDontSee('node-app')
            ->set('search', '"openssl\\')
            ->assertDontSee('go-app');
    });

    it('decodes at most the capped number of scans and says so', function () {
        foreach (['first-app', 'second-app'] as $name) {
            fleetScan(fleetApplication($name), 'ok', ['findings' => [fleetFinding(['package' => 'zlib'])]]);
        }
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $fleet = TeamVulnerabilities::load($this->team->id);
        $scanned = $fleet->entries->filter(fn (array $entry): bool => $entry['scanned']);

        expect($fleet->search($scanned, 'zlib', maxScans: 1))->toHaveCount(1)
            ->and($fleet->searchWasCapped())->toBeTrue()
            ->and($fleet->search($scanned, 'zlib'))->toHaveCount(2)
            ->and($fleet->searchWasCapped())->toBeFalse();
    });

    it('reads the current scans once for the statuses and the search', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');
        fleetScan(fleetApplication('ok-app'), 'ok');
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $fleet = TeamVulnerabilities::load($this->team->id);
        $scanQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "image_scans"'))->count();
        DB::disableQueryLog();

        // One query finds the current scan IDs, one reads those scans.
        expect($scanQueries)->toBe(2)
            ->and($fleet->entries->firstWhere('resource.name', 'now-app')['security']->status->value)->toBe('update_now');
    });

    it('words the not scanned line for one resource and resources without a server', function () {
        expect(FleetVulnerabilities::notScannedSentence(1, 1))->toBe('1 resource on 1 server was not scanned, so Coolify cannot tell.')
            ->and(FleetVulnerabilities::notScannedSentence(1, 0))->toBe('1 resource was not scanned, so Coolify cannot tell.')
            ->and(FleetVulnerabilities::notScannedSentence(3, 2))->toBe('3 resources on 2 servers were not scanned, so Coolify cannot tell.');
    });
});

describe('markers', function () {
    it('marks only update now (with the primary action) and no fix yet', function () {
        $database = StandalonePostgresql::forceCreate([
            'name' => 'pg', 'postgres_password' => 'secret', 'image' => 'postgres:16.3-alpine',
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        ]);
        fleetScan($database, 'update_now', ['image_ref' => 'postgres:16.3-alpine', 'update' => '16.4-alpine']);
        $noFix = fleetApplication('nofix-app');
        fleetScan($noFix, 'no_fix');
        $others = collect(['update_available', 'check_needed', 'ok'])->map(function (string $status): Application {
            $application = fleetApplication("{$status}-app");
            fleetScan($application, $status);

            return $application;
        });
        $never = fleetApplication('never-app');

        $markers = VulnerabilityMarkers::for(collect([$database, $noFix, $never, ...$others]));

        expect($markers)->toBe([
            ResourceScans::key($database) => ['status' => 'update_now', 'text' => 'Update now', 'label' => 'Security: update now. Update to 16.4-alpine.', 'tooltip' => 'Update to 16.4-alpine.'],
            ResourceScans::key($noFix) => ['status' => 'no_fix', 'text' => 'No fix yet', 'label' => 'Security: no fix yet', 'tooltip' => null],
        ]);
    });

    it('renders update now in the error color and no fix yet in the neutral color, with visible text', function () {
        $html = (string) $this->blade('<x-image-scan.marker status="update_now" text="Update now" label="Security: update now. Update to 1.28." tooltip="Update to 1.28." />');
        expect($html)->toMatch('/<svg[^>]*text-error/')
            ->not->toMatch('/<span role="img"[^>]*text-error/')
            ->toContain('>Update now</span>')
            ->toContain('aria-label="Security: update now. Update to 1.28."')
            ->toContain('data-tooltip="Update to 1.28." data-tooltip-wrap');

        $html = (string) $this->blade('<x-image-scan.marker status="no_fix" text="No fix yet" label="Security: no fix yet" />');
        expect($html)->not->toContain('text-error')
            ->toContain('>No fix yet</span>')
            ->not->toContain('data-tooltip');

        expect(trim((string) $this->blade('<x-image-scan.marker status="update_available" text="Security update" label="x" />')))->toBe('');
    });

    it('reads the markers with the same number of queries for more resources', function () {
        $count = function (int $resources): int {
            $models = collect(range(1, $resources))->map(function (int $index) use ($resources) {
                $application = fleetApplication("app-{$resources}-{$index}");
                fleetScan($application, 'update_now');

                return $application->fresh();
            });
            $models->push(Service::factory()->create([
                'environment_id' => test()->environment->id, 'server_id' => test()->server->id,
                'destination_id' => test()->destination->id, 'destination_type' => test()->destination->getMorphClass(),
            ]));

            DB::flushQueryLog();
            DB::enableQueryLog();
            VulnerabilityMarkers::for($models);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        expect($count(6))->toBe($count(2));
    });

    it('adds the marker to the environment list', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');
        fleetScan(fleetApplication('available-app'), 'update_available');

        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $response = $this->get(route('project.resource.index', [
            'project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'view' => 'list',
        ]))->assertOk();

        $content = $response->getContent();
        expect($content)->toContain(fleetJsPayload('"security":{"status":"update_now","text":"Update now","label":"Security: update now. Update to 1.28.","tooltip":"Update to 1.28."}'))
            ->and(substr_count($content, fleetJsPayload('"security":{')))->toBe(1);
    });

    it('adds the marker to the server resources tab', function () {
        fleetScan(fleetApplication('now-app'), 'update_now');
        fleetScan(fleetApplication('available-app'), 'update_available');

        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $this->get(route('server.resources', ['server_uuid' => $this->server->uuid]))
            ->assertOk()
            ->assertSee('data-tooltip="Update to 1.28."', false)
            ->assertSee('>Update now</span>', false)
            ->assertDontSee('Security update');
    });

    it('adds the marker to canvas cards', function () {
        $application = fleetApplication('marked-app');
        fleetScan($application, 'no_fix');

        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $payload = app(EnvironmentCanvas::class)->payload($this->environment, true, true);

        expect(collect($payload['nodes'])->firstWhere('id', $application->uuid)['security'])
            ->toBe(['status' => 'no_fix', 'text' => 'No fix yet', 'label' => 'Security: no fix yet', 'tooltip' => null]);
    });

    it('hides markers when the instance does not allow scanning', function () {
        $application = fleetApplication('marked-app');
        fleetScan($application, 'update_now');
        InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false]);
        Once::flush();

        expect(VulnerabilityMarkers::for(collect([$application->fresh()])))->toBe([]);
    });
});

describe('dashboard and search', function () {
    it('shows the dashboard callout only for resources that need an update now', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        fleetScan(fleetApplication('available-app'), 'update_available');
        fleetScan(fleetApplication('nofix-app'), 'no_fix');

        Livewire::test(Dashboard::class)->assertDontSee('a security update now.');

        $scan = fleetScan(fleetApplication('now-app'), 'update_now');

        $now = Application::query()->where('name', 'now-app')->firstOrFail();
        Livewire::test(Dashboard::class)
            ->assertSee('1 resource needs a security update now.')
            ->assertSee('Review')
            ->assertSeeHtml($now->uuid)
            ->assertSeeHtml('coolify.image-scan.dashboard-update-now-dismissed.'.$this->team->id)
            ->assertSeeHtml('href="'.e(route('vulnerabilities', ['statuses' => ['update_now']])).'"');

        fleetScan(fleetApplication('other-now-app'), 'update_now');

        Livewire::test(Dashboard::class)->assertSee('2 resources need a security update now.');

        expect(Dashboard::securityCalloutTitle(1))->toBe('1 resource needs a security update now.')
            ->and(Dashboard::securityCalloutTitle(3))->toBe('3 resources need a security update now.');
    });

    it('offers the Security page and a search for a vulnerability ID', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        $search = Livewire::test(GlobalSearch::class)->call('openSearchModal');
        $entry = collect($search->get('allSearchableItems'))->firstWhere('link', route('vulnerabilities'));
        expect($entry['name'])->toBe('Security');

        // The palette builds the vulnerability ID entry in the browser (resources/js/vulnerability-search.js, tested
        // with node --test) from this URL.
        $search->assertSeeHtml('window.vulnerabilitySearchItem?.(this.searchQuery, this.vulnerabilitiesUrl)')
            ->assertDontSeeHtml('vulnerabilitiesUrl: null');

        InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false]);
        Once::flush();

        Livewire::test(GlobalSearch::class)->assertSeeHtml('vulnerabilitiesUrl: null');
    });

    it('lists the Security page in the navbar only when the instance allows scanning', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        app()->instance('request', Request::create('/vulnerabilities'));

        $navbar = (string) $this->blade('<x-navbar desktop />');
        expect($navbar)->toContain('href="'.route('vulnerabilities').'"')
            ->and($navbar)->toMatch('/href="'.preg_quote(route('vulnerabilities'), '/').'"[^>]*>.*?Security/s')
            ->and($navbar)->not->toContain('Vulnerabilities');

        InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false]);
        Once::flush();

        expect((string) $this->blade('<x-navbar desktop />'))->not->toContain('href="'.route('vulnerabilities').'"');
    });
});

describe('one scanned rule', function () {
    it('treats a resource on a server with scanning off as not scanned everywhere', function () {
        $application = fleetApplication('marked-app');
        fleetScan($application, 'update_now');
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();
        $application = $application->fresh();

        fleetExpectNotScannedEverywhere($this, $application);
    });

    it('treats an application with its own scan off as not scanned everywhere', function () {
        $application = fleetApplication('marked-app');
        fleetScan($application, 'update_now');
        $application->settings->forceFill(['is_image_scan_enabled' => false])->save();
        $application = $application->fresh();

        fleetExpectNotScannedEverywhere($this, $application);
    });

    it('counts the dashboard resources without loading every resource of the team', function () {
        $count = function (int $resources): int {
            collect(range(1, $resources))->each(fn (int $index) => fleetScan(fleetApplication('clean-'.$resources.'-'.$index), 'update_available'));
            DB::flushQueryLog();
            DB::enableQueryLog();
            TeamVulnerabilities::updateNowResources(test()->team->id);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        fleetScan(fleetApplication('marked-app'), 'update_now');

        expect($count(2))->toBe($count(6))
            ->and(TeamVulnerabilities::updateNowResources($this->team->id))->toHaveCount(1);
    });

    it('does not count a resource whose update now result needs a check', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        fleetScan(fleetApplication('stale-app'), 'update_now', ['scanned_at' => now()->subDays(3)]);

        expect(TeamVulnerabilities::updateNowResources($this->team->id))->toBe([]);
    });

    it('counts a resource scanned on a build server that does not scan like the markers do', function () {
        $application = fleetApplication('built-app');
        $buildServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->server->private_key_id]);
        fleetScan($application, 'update_now', ['server_id' => $buildServer->id]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        expect($buildServer->settings->is_image_scan_enabled)->toBeFalse()
            ->and(VulnerabilityMarkers::for(collect([$application->fresh()])))->not->toBe([])
            ->and(TeamVulnerabilities::updateNowResources($this->team->id))->toBe([$application->uuid]);
    });

    it('does not reload the page for progress updates of a running scan', function () {
        $component = fleetPage($this->admin);

        $component->call('scanUpdated', ['resourceUuid' => 'x', 'scanUuid' => 'run', 'final' => false]);
        expect($component->effects)->not->toHaveKey('html');

        $component->call('scanUpdated', ['resourceUuid' => 'x', 'scanUuid' => 'scan', 'final' => true]);
        expect($component->effects)->toHaveKey('html');
    });

    it('counts only the dashboard resources a limited member can see', function () {
        fleetScan(fleetApplication('visible-app'), 'update_now');
        $hiddenEnvironment = Environment::factory()->create([
            'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
        ]);
        fleetScan(fleetApplication('hidden-app', $hiddenEnvironment), 'update_now');

        $this->actingAs(fleetMember($this->team, 'member', [['scope' => 'project', 'uuid' => $this->project->uuid, 'levels' => ['read']]]));
        session(['currentTeam' => $this->team]);
        expect(TeamVulnerabilities::updateNowResources($this->team->id))->toHaveCount(1);

        $this->actingAs($this->admin);
        expect(TeamVulnerabilities::updateNowResources($this->team->id))->toHaveCount(2);
    });
});

function fleetExpectNotScannedEverywhere(mixed $test, Application $application): void
{
    $test->actingAs($test->admin);
    session(['currentTeam' => $test->team]);

    expect(VulnerabilityMarkers::for(collect([$application])))->toBe([])
        ->and(TeamVulnerabilities::updateNowResources($test->team->id))->toBe([])
        ->and(ImageScanPolicyResolver::offState($application))->not->toBeNull();

    fleetPage($test->admin)
        ->assertSeeInOrder(['marked-app', 'Not checked'])
        ->assertDontSeeHtml('<span class="truncate">Update now</span>')
        ->assertSee('1 resource on 1 server was not scanned, so Coolify cannot tell.');
}

describe('images of a resource', function () {
    it('says how many images of a service need an update, and nothing for a single image', function () {
        $service = Service::factory()->create([
            'name' => 'n8n-service', 'environment_id' => $this->environment->id, 'server_id' => $this->server->id,
            'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
        ]);
        foreach (['n8n' => 'update_now', 'runners' => 'update_now', 'postgres' => 'ok'] as $name => $status) {
            $owner = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => $name, 'image' => "example/{$name}:1.27"]);
            fleetScan($owner, $status, ['image_ref' => "example/{$name}:1.27"]);
        }
        fleetScan(fleetApplication('single-app'), 'update_now');

        fleetPage($this->admin)
            ->assertSeeInOrder(['n8n-service', 'Update now', '2 of 3 images'])
            ->assertDontSee('1 of 1 images');
    });

    it('counts an image of a Compose app without a result', function () {
        $application = fleetApplication('compose-app', attributes: [
            'build_pack' => 'dockercompose', 'docker_registry_image_name' => null,
            'docker_compose_raw' => "services:\n  web:\n    image: nginx:1.27\n  cache:\n    image: redis:7\n",
        ]);
        fleetScan($application, 'update_now');

        $entry = TeamVulnerabilities::load($this->team->id)->entries->firstWhere('resource.name', 'compose-app');

        expect($entry['images'])->toBe(['update' => 1, 'total' => 2])
            ->and($entry['security']->checkReason)->toBe('images_unchecked');
    });
});

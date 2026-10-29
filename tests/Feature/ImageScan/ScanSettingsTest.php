<?php

use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Jobs\RefreshImageScanSummariesJob;
use App\Livewire\ImageScan\InstanceImageScanning;
use App\Livewire\ImageScan\ScanSettings;
use App\Livewire\ImageScan\TeamImageScanning;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanPolicy;
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
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = scanSettingsMember($this->team, 'admin');
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function scanSettingsMember(Team $team, string $role = 'member', ?array $access = null): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user->fresh();
}

function scanSettingsAs(User $user, Team $sessionTeam, string $scopeType, ?string $scopeUuid = null): Testable
{
    test()->actingAs($user);
    session(['currentTeam' => $sessionTeam]);

    return Livewire::test(ScanSettings::class, ['scopeType' => $scopeType, 'scopeUuid' => $scopeUuid]);
}

function scanSettingsRow(Model $scope): ?ImageScanPolicy
{
    return ImageScanPolicy::query()
        ->where('scope_type', $scope->getMorphClass())
        ->where('scope_id', $scope->getKey())
        ->first();
}

function scanSettingsInstanceRow(): ImageScanPolicy
{
    return ImageScanPolicy::query()->whereNull('scope_type')->firstOrFail();
}

function scanSettingsInstanceAdmin(): User
{
    $rootTeam = Team::factory()->create(['id' => 0]);

    return scanSettingsMember($rootTeam, 'owner');
}

describe('instance scope', function () {
    it('saves the instance defaults', function () {
        $admin = scanSettingsInstanceAdmin();

        scanSettingsAs($admin, Team::find(0), 'instance')
            ->set('gateMode', 'strict')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->set('onScanError', 'block');

        $row = scanSettingsInstanceRow();
        expect($row->gate_mode)->toBe(ImageScanGateMode::Strict)
            ->and($row->on_scan_error)->toBe(ImageScanOnError::Block);
    });

    it('never nulls the instance row', function () {
        $admin = scanSettingsInstanceAdmin();

        scanSettingsAs($admin, Team::find(0), 'instance')
            ->assertDontSee('Use Coolify default')
            ->set('gateMode', '')
            ->assertHasErrors('gateMode')
            ->set('onScanError', '')
            ->assertHasErrors('onScanError');

        $row = scanSettingsInstanceRow();
        expect($row->gate_mode)->toBe(ImageScanGateMode::Off)
            ->and($row->on_scan_error)->toBe(ImageScanOnError::Deploy);
    });

    it('rejects a gate mode that does not exist', function () {
        $admin = scanSettingsInstanceAdmin();

        scanSettingsAs($admin, Team::find(0), 'instance')
            ->set('gateMode', 'always')
            ->assertHasErrors('gateMode');

        expect(scanSettingsInstanceRow()->gate_mode)->toBe(ImageScanGateMode::Off);
    });

    it('offers Off, On, and Strict with what On and Strict block', function () {
        scanSettingsAs(scanSettingsInstanceAdmin(), Team::find(0), 'instance')
            ->assertSee('Block deploys')
            ->assertSee('Strict')
            ->assertSee('Blocks a deploy when the new image adds a problem that attackers are using or are likely to use.')
            ->assertSee('Also blocks critical and high problems that have a fix, and operating systems that no longer get security fixes.')
            ->assertDontSee('Alert on');
    });

    it('forbids the instance rules to users who are not instance admins', function () {
        scanSettingsAs($this->admin, $this->team, 'instance')->assertForbidden();
    });
});

describe('instance page', function () {
    it('redirects users who are not instance admins', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(InstanceImageScanning::class)->assertRedirect(route('dashboard'));
    });

    it('turns image scanning off for the instance', function () {
        $admin = scanSettingsInstanceAdmin();
        $this->actingAs($admin);
        session(['currentTeam' => Team::find(0)]);

        Livewire::test(InstanceImageScanning::class)
            ->set('isImageScanningAllowed', '0')
            ->assertDispatched('success');

        expect(InstanceSettings::query()->find(0)->is_image_scanning_allowed)->toBeFalse();
    });

    it('saves an https vulnerability database URL and rejects http and junk', function (string $url, bool $valid) {
        $admin = scanSettingsInstanceAdmin();
        $this->actingAs($admin);
        session(['currentTeam' => Team::find(0)]);

        $component = Livewire::test(InstanceImageScanning::class)
            ->set('imageScanDbUrl', $url)
            ->call('saveDatabaseUrl');

        if ($valid) {
            $component->assertHasNoErrors();
            expect(InstanceSettings::query()->find(0)->image_scan_db_url)->toBe($url);
        } else {
            $component->assertHasErrors('imageScanDbUrl');
            expect(InstanceSettings::query()->find(0)->image_scan_db_url)->toBeNull();
        }
    })->with([
        'https' => ['https://mirror.example.com/grype/listing.json', true],
        'http' => ['http://mirror.example.com/grype/listing.json', false],
        'junk' => ['not a url', false],
    ]);
});

describe('team scope', function () {
    it('saves the team rules and stores null for the Coolify default', function () {
        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->assertSee('Use Coolify default (Off)')
            ->set('gateMode', 'on')
            ->assertDispatched('success');

        expect(scanSettingsRow($this->team)->gate_mode)->toBe(ImageScanGateMode::On);

        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->set('gateMode', '')
            ->assertHasNoErrors();

        expect(scanSettingsRow($this->team)->gate_mode)->toBeNull();
    });

    it('saves the default for new servers', function () {
        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->set('scanNewServers', '1')
            ->assertDispatched('success');

        expect($this->team->fresh()->scan_images_on_new_servers)->toBeTrue();
    });

    it('does not let a member change the team rules', function () {
        $member = scanSettingsMember($this->team);

        scanSettingsAs($member, $this->team, 'team', (string) $this->team->id)
            ->set('gateMode', 'strict')
            ->assertForbidden();

        expect(scanSettingsRow($this->team))->toBeNull();
    });

    it('edits the team of the page after the session switched to another team', function () {
        $otherTeam = Team::factory()->create();
        $this->admin->teams()->attach($otherTeam, ['role' => 'owner']);

        $component = scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id);
        session(['currentTeam' => $otherTeam]);
        $component->set('gateMode', 'strict')->assertHasNoErrors();

        expect(scanSettingsRow($this->team)->gate_mode)->toBe(ImageScanGateMode::Strict)
            ->and(scanSettingsRow($otherTeam))->toBeNull();
    });

    it('returns not found for a team the user is not a member of', function () {
        $otherTeam = Team::factory()->create();

        scanSettingsAs($this->admin, $this->team, 'team', (string) $otherTeam->id)->assertNotFound();
    });

    it('shows the team page read-only to members', function () {
        $member = scanSettingsMember($this->team);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        Livewire::test(TeamImageScanning::class)->assertOk()->assertSee('Rules');
    });
});

describe('server scope', function () {
    it('names the level and value it inherits from', function () {
        ImageScanPolicy::query()->create([
            'scope_type' => $this->team->getMorphClass(),
            'scope_id' => $this->team->id,
            'gate_mode' => ImageScanGateMode::Strict,
        ]);

        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)
            ->assertSee('Use team default (Strict)')
            ->assertSee('Use Coolify default (Deploy anyway)')
            ->assertSee('Block deploys applies only to apps.');
    });

    it('saves the server rules and stores null for the inherited value', function () {
        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)
            ->set('gateMode', 'on')
            ->assertDispatched('success');

        expect(scanSettingsRow($this->server)->gate_mode)->toBe(ImageScanGateMode::On);

        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)
            ->set('gateMode', '')
            ->assertHasNoErrors();

        expect(scanSettingsRow($this->server)->gate_mode)->toBeNull();
    });

    it('forbids a change after the session switched to a team the user owns', function () {
        $member = scanSettingsMember($this->team);
        $ownTeam = Team::factory()->create();
        $member->teams()->attach($ownTeam, ['role' => 'owner']);

        $component = scanSettingsAs($member, $this->team, 'server', $this->server->uuid);
        session(['currentTeam' => $ownTeam]);
        $component->set('gateMode', 'strict')->assertForbidden();

        expect(scanSettingsRow($this->server))->toBeNull();
    });

    it('returns not found for a server of another team', function () {
        $stranger = scanSettingsMember(Team::factory()->create(), 'owner');

        scanSettingsAs($stranger, $stranger->teams->first(), 'server', $this->server->uuid)->assertNotFound();
    });
});

describe('application scope', function () {
    it('saves the app rules', function () {
        scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->set('onScanError', 'block')
            ->assertDispatched('success');

        expect(scanSettingsRow($this->application)->on_scan_error)->toBe(ImageScanOnError::Block);
    });

    it('does not let a user with a deploy-only grant change the app rules', function () {
        $deployer = scanSettingsMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'deploy']],
        ]);

        scanSettingsAs($deployer, $this->team, 'application', $this->application->uuid)
            ->set('gateMode', 'off')
            ->assertForbidden();

        expect(scanSettingsRow($this->application))->toBeNull();
    });

    it('returns not found for an app of another team', function () {
        $stranger = scanSettingsMember(Team::factory()->create(), 'owner');

        scanSettingsAs($stranger, $stranger->teams->first(), 'application', $this->application->uuid)->assertNotFound();
    });

    it('disables block deploys while the app scan is off', function () {
        scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->set('appScanEnabled', '0')
            ->assertDispatched('success')
            ->assertSee('Turn on the scan for this app first.')
            ->set('gateMode', 'on')
            ->assertDispatched('error');

        expect($this->application->settings->fresh()->is_image_scan_enabled)->toBeFalse()
            ->and(scanSettingsRow($this->application)?->gate_mode)->toBeNull();
    });

    it('saves who builds a Docker image app', function () {
        $this->application->update(['build_pack' => 'dockerimage']);

        $component = scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->assertSee('Who builds this image')
            ->set('buildsByTeam', '0')
            ->assertDispatched('success');

        expect($this->application->settings->fresh()->image_builds_by_team)->toBeFalse();

        $component->set('buildsByTeam', '');
        expect($this->application->settings->fresh()->image_builds_by_team)->toBeNull();
    });
});

describe('ignore list', function () {
    beforeEach(function () {
        $this->teamIgnore = ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2024-11111']);
        $this->appIgnore = ImageScanIgnore::factory()->create([
            'team_id' => $this->team->id,
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'vulnerability_id' => 'CVE-2024-22222',
        ]);
    });

    it('lists and removes team-wide ignores on the team scope', function () {
        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->assertSee('CVE-2024-11111')
            ->assertDontSee('CVE-2024-22222')
            ->call('removeIgnore', $this->teamIgnore->uuid)
            ->assertDispatched('success');

        expect(ImageScanIgnore::query()->whereKey($this->teamIgnore->id)->exists())->toBeFalse();
    });

    it('does not let a member remove a team-wide ignore', function () {
        $member = scanSettingsMember($this->team);

        scanSettingsAs($member, $this->team, 'team', (string) $this->team->id)
            ->call('removeIgnore', $this->teamIgnore->uuid)
            ->assertForbidden();

        expect(ImageScanIgnore::query()->whereKey($this->teamIgnore->id)->exists())->toBeTrue();
    });

    it('cannot remove an ignore of another team', function () {
        $otherTeam = Team::factory()->create();
        $otherIgnore = ImageScanIgnore::factory()->create(['team_id' => $otherTeam->id]);

        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->call('removeIgnore', $otherIgnore->uuid)
            ->assertNotFound();

        expect(ImageScanIgnore::query()->whereKey($otherIgnore->id)->exists())->toBeTrue();
    });

    it('shows team-wide ignores read-only on the app scope and removes app ignores', function () {
        scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->assertSee('CVE-2024-11111')
            ->assertSee('All team resources')
            ->assertSee('CVE-2024-22222')
            ->call('removeIgnore', $this->teamIgnore->uuid)
            ->assertNotFound();

        expect(ImageScanIgnore::query()->whereKey($this->teamIgnore->id)->exists())->toBeTrue();

        scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->call('removeIgnore', $this->appIgnore->uuid)
            ->assertDispatched('success');

        expect(ImageScanIgnore::query()->whereKey($this->appIgnore->id)->exists())->toBeFalse();
    });

    it('links team-wide ignores to the team settings only for the session team with team-wide access', function () {
        scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->assertSee('Team settings');

        $ownTeam = Team::factory()->create();
        $this->admin->teams()->attach($ownTeam, ['role' => 'owner']);
        scanSettingsAs($this->admin->fresh(), $ownTeam, 'application', $this->application->uuid)
            ->assertSee('CVE-2024-11111')
            ->assertDontSee('Team settings');

        $limited = scanSettingsMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'write']],
        ]);
        scanSettingsAs($limited, $this->team, 'application', $this->application->uuid)
            ->assertSee('CVE-2024-11111')
            ->assertDontSee('Team settings');
    });

    it('queues a refresh of the team counts after a team-wide ignore is removed', function () {
        Queue::fake();

        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->call('removeIgnore', $this->teamIgnore->uuid);

        Queue::assertPushed(RefreshImageScanSummariesJob::class, fn (RefreshImageScanSummariesJob $job) => $job->teamId === $this->team->id);
    });

    it('refreshes the stored count of the app after an app ignore is removed', function () {
        $scan = ImageScan::factory()->create([
            'server_id' => $this->server->id,
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'builds_image' => true,
            'findings' => [[
                'id' => 'CVE-2024-22222', 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '1',
                'type' => 'npm', 'artifactId' => 'a', 'fixedIn' => '2', 'fixState' => 'fixed', 'kev' => false, 'epss' => null,
                'risk' => 1.0, 'summary' => '', 'layer' => 'app',
            ]],
            'summary' => ['ignored' => 1],
        ]);

        scanSettingsAs($this->admin, $this->team, 'application', $this->application->uuid)
            ->call('removeIgnore', $this->appIgnore->uuid);

        expect(ResourceScans::storedFixedCount($scan->fresh()))->toBe(1)
            ->and($scan->fresh()->summary['ignored'])->toBe(0);
    });

    it('does not let a deploy-only user remove an app ignore', function () {
        $deployer = scanSettingsMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'deploy']],
        ]);

        scanSettingsAs($deployer, $this->team, 'application', $this->application->uuid)
            ->call('removeIgnore', $this->appIgnore->uuid)
            ->assertForbidden();

        expect(ImageScanIgnore::query()->whereKey($this->appIgnore->id)->exists())->toBeTrue();
    });
});

describe('ignore list of a database and a service', function () {
    beforeEach(function () {
        Queue::fake();
        $environment = $this->application->environment;
        $destination = $this->application->destination;
        $this->database = create_standalone_postgresql($environment->id, $destination);
        $this->service = Service::factory()->create([
            'environment_id' => $environment->id,
            'server_id' => $this->server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);
        $this->serviceApp = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $this->service->id, 'name' => 'web', 'image' => 'nginx:1.27']);
        $this->serviceDb = ServiceDatabase::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $this->service->id, 'name' => 'db', 'image' => 'postgres:16']);

        $ignore = fn (Model $owner, string $id): ImageScanIgnore => ImageScanIgnore::factory()->create([
            'team_id' => $this->team->id, 'resource_type' => $owner->getMorphClass(), 'resource_id' => $owner->getKey(), 'vulnerability_id' => $id,
        ]);
        $this->teamIgnore = ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2024-10000']);
        $this->databaseIgnore = $ignore($this->database, 'CVE-2024-10001');
        $this->serviceAppIgnore = $ignore($this->serviceApp, 'CVE-2024-10002');
        $this->serviceDbIgnore = $ignore($this->serviceDb, 'CVE-2024-10003');
        $this->appIgnore = $ignore($this->application, 'CVE-2024-10004');
    });

    it('lists the ignores of a database with the team-wide ones and removes a database ignore', function () {
        scanSettingsAs($this->admin, $this->team, 'database', $this->database->uuid)
            ->assertSee('Ignored findings')
            ->assertSeeInOrder(['CVE-2024-10001', 'This resource', 'CVE-2024-10000', 'All team resources'])
            ->assertDontSee('CVE-2024-10002')
            ->assertDontSee('CVE-2024-10004')
            ->assertDontSee('If the scan cannot run')
            ->call('removeIgnore', $this->databaseIgnore->uuid)
            ->assertDispatched('success');

        expect(ImageScanIgnore::query()->whereKey($this->databaseIgnore->id)->exists())->toBeFalse();
    });

    it('lists the ignores of the applications and databases of a service with their names and removes them', function () {
        scanSettingsAs($this->admin, $this->team, 'service', $this->service->uuid)
            ->assertSee('CVE-2024-10002')
            ->assertSee('CVE-2024-10003')
            ->assertSee('CVE-2024-10000')
            ->assertDontSee('CVE-2024-10001')
            ->assertSeeInOrder(['CVE-2024-10003', 'db'])
            ->call('removeIgnore', $this->serviceDbIgnore->uuid)
            ->assertDispatched('success')
            ->call('removeIgnore', $this->serviceAppIgnore->uuid)
            ->assertDispatched('success');

        expect(ImageScanIgnore::query()->whereKey([$this->serviceAppIgnore->id, $this->serviceDbIgnore->id])->exists())->toBeFalse();
    });

    it('does not remove a team-wide ignore or an ignore of another resource from a resource page', function (string $scope) {
        $uuid = $scope === 'database' ? $this->database->uuid : $this->service->uuid;
        $other = $scope === 'database' ? $this->serviceAppIgnore : $this->databaseIgnore;

        scanSettingsAs($this->admin, $this->team, $scope, $uuid)->call('removeIgnore', $this->teamIgnore->uuid)->assertNotFound();
        scanSettingsAs($this->admin, $this->team, $scope, $uuid)->call('removeIgnore', $other->uuid)->assertNotFound();
        scanSettingsAs($this->admin, $this->team, $scope, $uuid)->call('removeIgnore', $this->appIgnore->uuid)->assertNotFound();

        expect(ImageScanIgnore::query()->whereKey([$this->teamIgnore->id, $other->id, $this->appIgnore->id])->count())->toBe(3);
    })->with(['database', 'service']);

    it('lets a member with write access remove an ignore, and not a member with read or deploy access', function (string $scope) {
        [$uuid, $ignore] = $scope === 'database' ? [$this->database->uuid, $this->databaseIgnore] : [$this->service->uuid, $this->serviceAppIgnore];

        foreach ([null, [['scope' => 'resource', 'uuid' => $uuid, 'levels' => ['read', 'deploy']]]] as $access) {
            scanSettingsAs(scanSettingsMember($this->team, 'member', $access), $this->team, $scope, $uuid)
                ->assertSee($ignore->vulnerability_id)
                ->assertDontSee('Remove')
                ->call('removeIgnore', $ignore->uuid)
                ->assertForbidden();
        }
        expect(ImageScanIgnore::query()->whereKey($ignore->id)->exists())->toBeTrue();

        $writer = scanSettingsMember($this->team, 'member', [['scope' => 'resource', 'uuid' => $uuid, 'levels' => ['read', 'write']]]);
        scanSettingsAs($writer, $this->team, $scope, $uuid)
            ->call('removeIgnore', $ignore->uuid)
            ->assertDispatched('success');

        expect(ImageScanIgnore::query()->whereKey($ignore->id)->exists())->toBeFalse();
    })->with(['database', 'service']);

    it('decides with the team of the resource after the session switches to a team the user owns', function (string $scope) {
        [$uuid, $ignore] = $scope === 'database' ? [$this->database->uuid, $this->databaseIgnore] : [$this->service->uuid, $this->serviceAppIgnore];
        $user = scanSettingsMember($this->team);
        $ownTeam = Team::factory()->create();
        $user->teams()->attach($ownTeam, ['role' => 'owner']);
        $user = $user->fresh();

        $page = scanSettingsAs($user, $this->team, $scope, $uuid);
        session(['currentTeam' => $ownTeam]);
        $page->call('removeIgnore', $ignore->uuid)->assertForbidden();

        expect(ImageScanIgnore::query()->whereKey($ignore->id)->exists())->toBeTrue();
    })->with(['database', 'service']);

    it('does not open the ignores of a resource of another team', function (string $scope) {
        $uuid = $scope === 'database' ? $this->database->uuid : $this->service->uuid;
        $otherTeam = Team::factory()->create();
        $stranger = scanSettingsMember($otherTeam, 'owner');

        scanSettingsAs($stranger, $otherTeam, $scope, $uuid)->assertNotFound();
    })->with(['database', 'service']);

    it('refreshes the stored counts of the service after an ignore is removed', function () {
        $scan = ImageScan::factory()->create([
            'server_id' => $this->server->id,
            'resource_type' => $this->serviceApp->getMorphClass(),
            'resource_id' => $this->serviceApp->id,
            'image_ref' => 'nginx:1.27',
            'builds_image' => false,
            'findings' => [[
                'id' => 'CVE-2024-10002', 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '1',
                'type' => 'apk', 'artifactId' => 'a', 'fixedIn' => '2', 'fixState' => 'fixed', 'kev' => false, 'epss' => null,
                'risk' => 1.0, 'summary' => '', 'layer' => 'app',
            ]],
            'summary' => ['ignored' => 1],
        ]);

        scanSettingsAs($this->admin, $this->team, 'service', $this->service->uuid)
            ->call('removeIgnore', $this->serviceAppIgnore->uuid);

        expect($scan->fresh()->summary['ignored'])->toBe(0);
    });

    it('has no rules to save on a database or service page', function (string $scope) {
        $uuid = $scope === 'database' ? $this->database->uuid : $this->service->uuid;

        scanSettingsAs($this->admin, $this->team, $scope, $uuid)->set('gateMode', 'strict')->assertNotFound();

        expect(ImageScanPolicy::query()->whereNotNull('scope_type')->count())->toBe(0);
    })->with(['database', 'service']);
});

describe('stored statuses', function () {
    it('does not refresh the stored statuses when a rule changes', function (string $property, string $value) {
        Queue::fake();

        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)->set($property, $value)->assertDispatched('success');

        Queue::assertNotPushed(RefreshImageScanSummariesJob::class);
    })->with([
        'block deploys' => ['gateMode', 'strict'],
        'if the scan cannot run' => ['onScanError', 'block'],
    ]);
});

<?php

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanOnError;
use App\Livewire\ImageScan\InstanceImageScanning;
use App\Livewire\ImageScan\ScanSettings;
use App\Livewire\ImageScan\TeamImageScanning;
use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
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
            ->set('alertLevel', 'high')
            ->assertHasNoErrors()
            ->assertDispatched('success')
            ->set('onScanError', 'block')
            ->set('gateEnabled', '1');

        $row = scanSettingsInstanceRow();
        expect($row->alert_level)->toBe(ImageScanAlertLevel::High)
            ->and($row->on_scan_error)->toBe(ImageScanOnError::Block)
            ->and($row->gate_enabled)->toBeTrue();
    });

    it('never nulls the instance row', function () {
        $admin = scanSettingsInstanceAdmin();

        scanSettingsAs($admin, Team::find(0), 'instance')
            ->assertDontSee('Use Coolify default')
            ->set('alertLevel', '')
            ->assertHasErrors('alertLevel')
            ->set('gateEnabled', '')
            ->assertHasErrors('gateEnabled');

        $row = scanSettingsInstanceRow();
        expect($row->alert_level)->toBe(ImageScanAlertLevel::Critical)
            ->and($row->gate_enabled)->toBeFalse();
    });

    it('rejects an alert level that does not exist', function () {
        $admin = scanSettingsInstanceAdmin();

        scanSettingsAs($admin, Team::find(0), 'instance')
            ->set('alertLevel', 'everything')
            ->assertHasErrors('alertLevel');

        expect(scanSettingsInstanceRow()->alert_level)->toBe(ImageScanAlertLevel::Critical);
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
            ->assertSee('Use Coolify default (Critical with a fix)')
            ->set('alertLevel', 'medium')
            ->assertDispatched('success');

        expect(scanSettingsRow($this->team)->alert_level)->toBe(ImageScanAlertLevel::Medium);

        scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id)
            ->set('alertLevel', '')
            ->assertHasNoErrors();

        expect(scanSettingsRow($this->team)->alert_level)->toBeNull();
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
            ->set('alertLevel', 'high')
            ->assertForbidden();

        expect(scanSettingsRow($this->team))->toBeNull();
    });

    it('edits the team of the page after the session switched to another team', function () {
        $otherTeam = Team::factory()->create();
        $this->admin->teams()->attach($otherTeam, ['role' => 'owner']);

        $component = scanSettingsAs($this->admin, $this->team, 'team', (string) $this->team->id);
        session(['currentTeam' => $otherTeam]);
        $component->set('alertLevel', 'high')->assertHasNoErrors();

        expect(scanSettingsRow($this->team)->alert_level)->toBe(ImageScanAlertLevel::High)
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
            'alert_level' => ImageScanAlertLevel::High,
        ]);

        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)
            ->assertSee('Use team default (High or critical with a fix)')
            ->assertSee('Use Coolify default (Deploy anyway)')
            ->assertSee('Services and databases use only the alert setting.');
    });

    it('saves the server rules and stores null for the inherited value', function () {
        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)
            ->set('gateEnabled', '1')
            ->assertDispatched('success');

        expect(scanSettingsRow($this->server)->gate_enabled)->toBeTrue();

        scanSettingsAs($this->admin, $this->team, 'server', $this->server->uuid)
            ->set('gateEnabled', '')
            ->assertHasNoErrors();

        expect(scanSettingsRow($this->server)->gate_enabled)->toBeNull();
    });

    it('forbids a change after the session switched to a team the user owns', function () {
        $member = scanSettingsMember($this->team);
        $ownTeam = Team::factory()->create();
        $member->teams()->attach($ownTeam, ['role' => 'owner']);

        $component = scanSettingsAs($member, $this->team, 'server', $this->server->uuid);
        session(['currentTeam' => $ownTeam]);
        $component->set('alertLevel', 'high')->assertForbidden();

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
            ->set('alertLevel', 'high')
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
            ->set('gateEnabled', '1')
            ->assertDispatched('error');

        expect($this->application->settings->fresh()->is_image_scan_enabled)->toBeFalse()
            ->and(scanSettingsRow($this->application)?->gate_enabled)->toBeNull();
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

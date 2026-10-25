<?php

use App\Actions\Server\CheckUpdates;
use App\Actions\Server\ConfigureAutomaticSecurityUpdates;
use App\Actions\Server\RebootServer;
use App\Actions\Server\UpdatePackage;
use App\Events\ServerPackagesUpdateFinished;
use App\Events\ServerPatchCheckFinished;
use App\Jobs\ServerManagerJob;
use App\Jobs\ServerPatchCheckJob;
use App\Livewire\Server\Security\Patches;
use App\Models\AuditEvent;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Server\ServerPatchCheck;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin, ['role' => 'admin']);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->forceFill(['is_reachable' => true, 'is_usable' => true])->saveQuietly();
});

function patchesTestResult(int $updates = 1): array
{
    return [
        'osId' => 'ubuntu',
        'package_manager' => 'apt',
        'total_updates' => $updates,
        'updates' => array_slice([
            ['package' => 'openssl', 'current_version' => '3.0.13-0ubuntu3.1', 'new_version' => '3.0.13-0ubuntu3.4', 'architecture' => 'amd64', 'repository' => 'noble-updates,noble-security'],
            ['package' => 'curl', 'current_version' => '8.5.0-2ubuntu10.1', 'new_version' => '8.5.0-2ubuntu10.6', 'architecture' => 'amd64', 'repository' => 'noble-updates'],
        ], 0, $updates),
    ];
}

function runPatchesTestJob(Server $server, bool $manual = false): void
{
    (new ServerPatchCheckJob($server->fresh(), $manual))->handle();
}

describe('patch check job', function () {
    beforeEach(function () {
        Notification::fake();
        Event::fake([ServerPatchCheckFinished::class]);
        $this->team->emailNotificationSettings()->update(['use_instance_email_settings' => true, 'server_patch_email_notifications' => true]);
    });

    it('saves the result, clears the running flag, and broadcasts to the server team', function () {
        CheckUpdates::shouldRun()->andReturn(patchesTestResult(2));
        Cache::put(ServerPatchCheckJob::runningCacheKey($this->server), true);

        runPatchesTestJob($this->server, manual: true);

        $settings = $this->server->settings->fresh();
        expect($settings->patch_check_result['total_updates'])->toBe(2)
            ->and($settings->patch_check_result['updates'][0]['package'])->toBe('openssl')
            ->and($settings->patch_check_error)->toBeNull()
            ->and($settings->patch_checked_at)->not->toBeNull()
            ->and(Cache::has(ServerPatchCheckJob::runningCacheKey($this->server)))->toBeFalse();
        Event::assertDispatched(ServerPatchCheckFinished::class, fn ($event) => $event->teamId === $this->team->id && $event->serverUuid === $this->server->uuid);
        Notification::assertNothingSent();
    });

    it('notifies about available updates on a scheduled check', function () {
        CheckUpdates::shouldRun()->andReturn(patchesTestResult());

        runPatchesTestJob($this->server);

        Notification::assertSentTo($this->team, ServerPatchCheck::class);
    });

    it('sends the failure notification only when the check changes from ok to error', function () {
        CheckUpdates::shouldRun()->andReturn(
            ['osId' => 'ubuntu', 'package_manager' => 'apt', 'error' => 'apt is locked'],
            ['osId' => 'ubuntu', 'package_manager' => 'apt', 'error' => 'apt is locked'],
            patchesTestResult(0),
            ['osId' => 'ubuntu', 'package_manager' => 'apt', 'error' => 'apt is locked again'],
        );

        runPatchesTestJob($this->server);
        runPatchesTestJob($this->server);
        expect($this->server->settings->fresh()->patch_check_error)->toBe('apt is locked');
        Notification::assertSentToTimes($this->team, ServerPatchCheck::class, 1);

        runPatchesTestJob($this->server);
        expect($this->server->settings->fresh()->patch_check_error)->toBeNull();

        runPatchesTestJob($this->server);
        Notification::assertSentToTimes($this->team, ServerPatchCheck::class, 2);
    });

    it('saves an error for a manual check of a server that is not functional', function () {
        $this->server->settings->forceFill(['is_reachable' => false])->saveQuietly();
        CheckUpdates::shouldRun()->never();

        runPatchesTestJob($this->server, manual: true);

        expect($this->server->settings->fresh()->patch_check_error)->toBe('Server is not reachable or not ready.');
        Event::assertDispatched(ServerPatchCheckFinished::class);
    });
});

describe('check frequency', function () {
    it('uses the server frequency instead of the weekly default', function () {
        $default = ServerManagerJob::patchCheckCron($this->server);
        expect($default)->toEndWith('* * 0');

        $this->server->settings->forceFill(['patch_check_frequency' => 'daily'])->saveQuietly();
        expect(ServerManagerJob::patchCheckCron($this->server->fresh()))->toBe('0 0 * * *');

        $this->server->settings->forceFill(['patch_check_frequency' => '30 3 * * 1'])->saveQuietly();
        expect(ServerManagerJob::patchCheckCron($this->server->fresh()))->toBe('30 3 * * 1');
    });

    it('accepts presets and cron expressions that run at most once per hour', function (string $frequency, bool $valid) {
        expect(Patches::isValidPatchCheckFrequency($frequency))->toBe($valid);
    })->with([
        ['daily', true],
        ['hourly', true],
        ['0 3 * * *', true],
        ['every_minute', false],
        ['*/30 * * * *', false],
        ['not a cron', false],
    ]);

    it('saves a valid frequency and rejects an invalid one', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->set('patchCheckFrequency', 'daily')
            ->call('submit')
            ->assertDispatched('success')
            ->set('patchCheckFrequency', '* * * * *')
            ->call('submit')
            ->assertDispatched('error');

        expect($this->server->settings->fresh()->patch_check_frequency)->toBe('daily');
    });
});

describe('patches page', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('shows the saved result of the last check', function () {
        $this->server->settings->forceFill(['patch_check_result' => patchesTestResult(2), 'patch_checked_at' => now()->subHour()])->saveQuietly();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('1 hour ago')
            ->assertSee('openssl')
            ->assertSee('8.5.0-2ubuntu10.6');
    });

    it('queues the check for an admin and shows the progress', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->call('checkForUpdates')
            ->assertSet('checking', true)
            ->assertSee('Inspecting installed packages');

        Queue::assertPushed(ServerPatchCheckJob::class, fn ($job) => $job->manual && $job->server->is($this->server));
    });

    it('refreshes from the saved result when the check finishes', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $component = Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])->call('checkForUpdates');

        $this->server->settings->forceFill(['patch_check_result' => patchesTestResult(), 'patch_checked_at' => now()])->saveQuietly();
        Cache::forget(ServerPatchCheckJob::runningCacheKey($this->server));

        $component->call('patchCheckFinished', ['serverUuid' => $this->server->uuid])
            ->assertSet('checking', false)
            ->assertSee('openssl');
    });

    it('shows held back updates apart from the updates and does not update them', function () {
        $result = [...patchesTestResult(0), 'held_updates' => [
            ['package' => 'cloud-init', 'current_version' => '25.1.4-0ubuntu0~24.04.1', 'new_version' => '26.2-0ubuntu1~24.04.2', 'architecture' => 'all', 'repository' => 'noble-updates', 'held_reason' => 'phased'],
            ['package' => 'tzdata', 'current_version' => '2025b-0ubuntu0.24.04', 'new_version' => '2025b-0ubuntu0.24.04.1', 'architecture' => 'all', 'repository' => 'noble-updates', 'held_reason' => 'held'],
        ]];
        $this->server->settings->forceFill(['patch_check_result' => $result, 'patch_checked_at' => now()])->saveQuietly();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('Server is up to date')
            ->assertSee('Held back updates')
            ->assertSee('Phased rollout')
            ->assertSee('26.2-0ubuntu1~24.04.2')
            ->assertSee('2025b-0ubuntu0.24.04.1')
            ->call('updatePackage', 'tzdata')
            ->assertDispatched('error', message: 'This package has no update in the last check.');
    });

    it('updates only a package from the saved result', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $this->server->settings->forceFill(['patch_check_result' => patchesTestResult(), 'patch_checked_at' => now()])->saveQuietly();

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->call('updatePackage', 'curl; reboot')
            ->assertDispatched('error', message: 'This package has no update in the last check.');
    });
});

describe('patches authorization', function () {
    beforeEach(function () {
        Queue::fake();
        $this->member = User::factory()->create();
        $this->team->members()->attach($this->member, ['role' => 'member']);
    });

    it('does not let a member open the page', function () {
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])->assertForbidden();
    });

    it('does not show a server of another team', function () {
        $otherTeam = Team::factory()->create();
        $this->admin->teams()->attach($otherTeam, ['role' => 'owner']);
        $this->actingAs($this->admin);
        session(['currentTeam' => $otherTeam]);

        expect(fn () => Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid]))->toThrow(ModelNotFoundException::class);
    });

    it('uses the role in the server team after the session switches to a team the user owns', function () {
        $otherTeam = Team::factory()->create();
        $this->member->teams()->attach($otherTeam, ['role' => 'owner']);
        $this->member->teams()->updateExistingPivot($this->team->id, ['role' => 'admin']);
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        $component = Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid]);

        // The user is now only a member of the server team, but owns the session team.
        $this->member->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
        $this->member->unsetRelation('teams');
        session(['currentTeam' => $otherTeam]);

        $component->call('checkForUpdates')->assertDispatched('error');
        $component->set('patchCheckFrequency', 'daily')->call('submit')->assertDispatched('error');

        Queue::assertNothingPushed();
        expect($this->server->settings->fresh()->patch_check_frequency)->toBeNull();
    });
});

it('runs a new check when a package update finishes', function () {
    Queue::fake();

    ServerPackagesUpdateFinished::dispatch(['server_uuid' => $this->server->uuid, 'packages' => ['curl']]);

    Queue::assertPushed(ServerPatchCheckJob::class, fn ($job) => $job->manual && $job->server->is($this->server));
    expect(Cache::has(ServerPatchCheckJob::runningCacheKey($this->server)))->toBeTrue();
});

describe('security, reboot, and risk', function () {
    beforeEach(function () {
        Queue::fake();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
    });

    it('shows security updates, risky packages, and the reboot action', function () {
        $result = (new CheckUpdates)->addUpdateDetails([
            'osId' => 'ubuntu', 'package_manager' => 'apt', 'total_updates' => 2,
            'updates' => [
                ['package' => 'curl', 'new_version' => '8.5.0-2ubuntu10.6', 'repository' => 'noble-updates'],
                ['package' => 'docker-ce', 'new_version' => '5:28.0.0-1', 'repository' => 'noble-security'],
            ],
        ], 'apt');
        $result['reboot_required'] = true;
        $this->server->settings->forceFill(['patch_check_result' => $result, 'patch_checked_at' => now()])->saveQuietly();

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('1 security update')
            ->assertSee('Reboot required')
            ->assertSee('Reboot server')
            ->assertSee(CheckUpdates::RISK_LABELS['docker'])
            ->assertSeeInOrder(['docker-ce', 'curl']);
    });

    it('reboots the server for an admin and checks it again later', function () {
        RebootServer::shouldRun()->once()->with(Mockery::on(fn ($server) => $server->is($this->server)));

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->call('rebootServer')
            ->assertDispatched('success');
    });

    it('does not let a member of the server team reboot after the session switches to a team the user owns', function () {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => 'admin']);
        $otherTeam = Team::factory()->create();
        $user->teams()->attach($otherTeam, ['role' => 'owner']);
        $this->actingAs($user);
        $component = Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid]);

        $user->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
        $user->unsetRelation('teams');
        session(['currentTeam' => $otherTeam]);
        RebootServer::shouldRun()->never();

        $component->call('rebootServer')->assertDispatched('error');
    });

    it('does not reboot a server that another instance manages', function () {
        $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'transferred']]])->saveQuietly();

        expect(fn () => RebootServer::run($this->server->fresh()))->toThrow(RuntimeException::class, 'managed by another Coolify instance');
    });

    it('marks the risky packages in the notification', function () {
        $result = (new CheckUpdates)->addUpdateDetails(['updates' => [
            ['package' => 'linux-image-6.8.0-45-generic', 'repository' => 'noble-security', 'current_version' => '1', 'new_version' => '2', 'architecture' => 'amd64'],
            ['package' => 'curl', 'repository' => 'noble-updates', 'current_version' => '1', 'new_version' => '2', 'architecture' => 'amd64'],
        ]], 'apt');

        $payload = (new ServerPatchCheck($this->server, [...$result, 'total_updates' => 2, 'reboot_required' => true]))->toWebhook();

        expect($payload['critical_packages_count'])->toBe(1)
            ->and($payload['security_updates'])->toBe(1)
            ->and($payload['reboot_required'])->toBeTrue();
    });
});

describe('automatic security updates', function () {
    beforeEach(function () {
        Queue::fake();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $this->server->settings->forceFill(['patch_check_result' => patchesTestResult(0), 'patch_checked_at' => now()])->saveQuietly();
    });

    it('turns automatic security updates on and off with the saved time', function () {
        $this->server->settings->forceFill(['auto_security_updates_time' => '04:30'])->saveQuietly();
        ConfigureAutomaticSecurityUpdates::shouldRun()->once()
            ->with(Mockery::on(fn ($server) => $server->is($this->server)), 'apt', true, '04:30');
        ConfigureAutomaticSecurityUpdates::shouldRun()->once()
            ->with(Mockery::on(fn ($server) => $server->is($this->server)), 'apt', false, '04:30');

        $component = Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('Turn on automatic security updates?')
            ->assertSee('all containers on this server stop and start again')
            ->call('toggleAutomaticSecurityUpdates')
            ->assertDispatched('success')
            ->assertSet('autoSecurityUpdates', true);
        expect($this->server->settings->fresh()->is_auto_security_updates_enabled)->toBeTrue();

        $component->call('toggleAutomaticSecurityUpdates')->assertSet('autoSecurityUpdates', false);
        expect($this->server->settings->fresh()->is_auto_security_updates_enabled)->toBeFalse();
    });

    it('does not let the client turn on automatic updates without the confirmed action', function () {
        ConfigureAutomaticSecurityUpdates::shouldRun()->never();

        expect(fn () => Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])->set('autoSecurityUpdates', true))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('applies a new maintenance window only when automatic updates are on', function () {
        ConfigureAutomaticSecurityUpdates::shouldRun()->once()
            ->with(Mockery::on(fn ($server) => $server->is($this->server)), 'apt', true, '05:00');

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->set('autoSecurityUpdatesTime', '04:00')
            ->call('submit')
            ->assertDispatched('success');
        expect($this->server->settings->fresh()->auto_security_updates_time)->toBe('04:00');

        $this->server->settings->forceFill(['is_auto_security_updates_enabled' => true])->saveQuietly();
        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->set('autoSecurityUpdatesTime', '05:00')
            ->call('submit')
            ->assertDispatched('success');
        expect($this->server->settings->fresh()->auto_security_updates_time)->toBe('05:00');
    });

    it('turns on automatic updates with the time in the field, also when it is not saved yet', function () {
        ConfigureAutomaticSecurityUpdates::shouldRun()->once()
            ->with(Mockery::on(fn ($server) => $server->is($this->server)), 'apt', true, '05:45');

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->set('autoSecurityUpdatesTime', '05:45')
            ->call('toggleAutomaticSecurityUpdates')
            ->assertDispatched('success');

        expect($this->server->settings->fresh())
            ->is_auto_security_updates_enabled->toBeTrue()
            ->auto_security_updates_time->toBe('05:45');
    });

    it('keeps the setting off when the server commands fail', function () {
        ConfigureAutomaticSecurityUpdates::shouldRun()->once()->andThrow(new RuntimeException('apt-get failed'));

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleAutomaticSecurityUpdates')
            ->assertDispatched('error')
            ->assertSet('autoSecurityUpdates', false);

        expect($this->server->settings->fresh()->is_auto_security_updates_enabled)->toBeFalse();
    });

    it('shows that other package managers are not supported', function () {
        $this->server->settings->forceFill(['patch_check_result' => [...patchesTestResult(0), 'package_manager' => 'pacman']])->saveQuietly();
        ConfigureAutomaticSecurityUpdates::shouldRun()->never();

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->assertSee('this server uses pacman')
            ->assertDontSee('Turn on automatic security updates?')
            ->call('toggleAutomaticSecurityUpdates')
            ->assertDispatched('error');
    });

    it('rejects an invalid maintenance window', function () {
        ConfigureAutomaticSecurityUpdates::shouldRun()->never();

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->set('autoSecurityUpdatesTime', '25:00')
            ->call('submit')
            ->assertDispatched('error');
    });

    it('does not let a member turn on automatic updates', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'admin']);
        $this->actingAs($member);
        $component = Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid]);
        $member->teams()->updateExistingPivot($this->team->id, ['role' => 'member']);
        $member->unsetRelation('teams');
        ConfigureAutomaticSecurityUpdates::shouldRun()->never();

        $component->call('toggleAutomaticSecurityUpdates')->assertDispatched('error');
        expect($this->server->settings->fresh()->is_auto_security_updates_enabled)->toBeFalse();
    });
});

describe('audit trail', function () {
    beforeEach(function () {
        Queue::fake();
        $this->withoutDefer();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $this->server->settings->forceFill(['patch_check_result' => patchesTestResult(), 'patch_checked_at' => now()])->saveQuietly();
    });

    it('records who requested a package update', function () {
        $activity = Activity::query()->create(['log_name' => 'default', 'description' => '', 'properties' => []]);
        UpdatePackage::shouldRun()->once()->andReturn($activity);

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])->call('updatePackage', 'openssl');

        $event = AuditEvent::query()->where('event', 'ui.server.packages_update_requested')->sole();
        expect($event->actor_email)->toBe($this->admin->email)
            ->and($event->team_id)->toBe($this->team->id)
            ->and($event->resource_uuid)->toBe($this->server->uuid)
            ->and($event->metadata['packages'])->toBe(['openssl'])
            ->and($event->metadata['activity_id'])->toBe($activity->id);
    });

    it('records the result of a finished package update and checks the server again', function (string $status, string $result) {
        $activity = Activity::query()->create(['log_name' => 'default', 'description' => '', 'properties' => [
            'type_uuid' => 'update-1', 'status' => $status, 'exitCode' => $status === 'finished' ? 0 : 100,
        ]]);
        auth()->logout();

        ServerPackagesUpdateFinished::dispatch([
            'update_id' => 'update-1', 'server_uuid' => $this->server->uuid, 'packages' => [], 'package_manager' => 'apt',
            'actor_id' => $this->admin->id, 'actor_name' => $this->admin->name, 'actor_email' => $this->admin->email,
        ]);

        $event = AuditEvent::query()->where('event', 'ui.server.packages_update_finished')->sole();
        expect($event->actor_email)->toBe($this->admin->email)
            ->and($event->team_id)->toBe($this->team->id)
            ->and($event->metadata)->toMatchArray(['packages' => 'all', 'result' => $result, 'activity_id' => $activity->id]);
        Queue::assertPushed(ServerPatchCheckJob::class);
    })->with([
        ['finished', 'success'],
        ['error', 'failed'],
    ]);

    it('records a reboot and a failed reboot', function () {
        RebootServer::shouldRun()->once();
        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])->call('rebootServer');

        RebootServer::shouldRun()->once()->andThrow(new RuntimeException('Server is not reachable or not ready.'));
        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])->call('rebootServer');

        expect(AuditEvent::query()->where('event', 'ui.server.reboot_requested')->orderBy('id')->get()->map(fn ($event) => $event->metadata['result'])->all())
            ->toBe(['started', 'failed']);
    });

    it('records a change of the automatic security updates', function () {
        ConfigureAutomaticSecurityUpdates::shouldRun()->once();

        Livewire::test(Patches::class, ['server_uuid' => $this->server->uuid])
            ->call('toggleAutomaticSecurityUpdates');

        $event = AuditEvent::query()->where('event', 'ui.server.auto_security_updates_updated')->sole();
        expect($event->actor_email)->toBe($this->admin->email)
            ->and($event->metadata)->toMatchArray(['enabled' => true, 'time' => '03:00', 'package_manager' => 'apt', 'result' => 'success']);
    });
});

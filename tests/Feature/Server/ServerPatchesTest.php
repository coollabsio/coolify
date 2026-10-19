<?php

use App\Actions\Server\CheckUpdates;
use App\Events\ServerPackagesUpdateFinished;
use App\Events\ServerPatchCheckFinished;
use App\Jobs\ServerManagerJob;
use App\Jobs\ServerPatchCheckJob;
use App\Livewire\Server\Security\Patches;
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
use Livewire\Livewire;

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
            ->call('savePatchCheckFrequency')
            ->assertDispatched('success')
            ->set('patchCheckFrequency', '* * * * *')
            ->call('savePatchCheckFrequency')
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
        $component->set('patchCheckFrequency', 'daily')->call('savePatchCheckFrequency')->assertDispatched('error');

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

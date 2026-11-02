<?php

use App\Actions\Server\UpdateCoolify;
use App\Jobs\CheckForUpdatesJob;
use App\Livewire\Settings\Updates;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\CoolifyUpgradeStatus;
use App\Support\RemoteProcessCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config([
        'app.env' => 'testing',
        'constants.coolify.version' => '4.5.0',
        'constants.coolify.helper_version' => '1.0.19',
        'constants.coolify.versions_url' => 'https://cdn.example.com/coolify/versions.json',
        'constants.coolify.upgrade_script_url' => 'https://cdn.example.com/coolify/upgrade.sh',
        'constants.ssh.mux_enabled' => false,
    ]);
    Team::factory()->create(['id' => 0]);
    Server::forceCreate(['id' => 0, 'name' => 'localhost', 'ip' => '127.0.0.1', 'user' => 'root', 'team_id' => 0, 'private_key_id' => 1]);
    InstanceSettings::forceCreate(['id' => 0, 'is_auto_update_enabled' => true, 'auto_update_frequency' => '0 0 * * *', 'update_check_frequency' => '0 * * * *']);
    Once::flush();
});

afterEach(function () {
    @unlink(storage_path('app/versions.json'));
});

function updateChannelTestUse(string $channel, ?string $customTag = null, ?string $preview = '4.6.0-beta.2', string $nightly = '4.6.0-beta.1.20261027.3'): void
{
    InstanceSettings::findOrFail(0)->update(['update_channel' => $channel, 'update_custom_tag' => $customTag]);
    Once::flush();
    Http::fake([
        'cdn.example.com/coolify/versions.json' => Http::response(array_filter([
            'stable' => ['coolify' => ['version' => '4.5.1']],
            'preview' => $preview ? ['coolify' => ['version' => $preview]] : null,
            'nightly' => ['coolify' => ['version' => $nightly]],
        ])),
    ]);
}

it('follows the newest version of the channel and the more stable ones', function (string $channel, string $version, string $upgradeScriptUrl) {
    updateChannelTestUse($channel);

    expect(fetch_latest_versions())->toMatchArray([
        'versions' => ['coolify' => ['version' => $version]],
        'upgrade_script_url' => $upgradeScriptUrl,
    ]);
})->with([
    'stable' => ['stable', '4.5.1', 'https://cdn.example.com/coolify/upgrade.sh'],
    'preview' => ['preview', '4.6.0-beta.2', 'https://cdn.example.com/coolify/preview/upgrade.sh'],
    'nightly while a newer beta waits for the next nightly' => ['nightly', '4.6.0-beta.2', 'https://cdn.example.com/coolify/preview/upgrade.sh'],
]);

it('asks only the stable channel of the CDN on the stable channel', function () {
    updateChannelTestUse('stable');

    fetch_latest_versions();

    Http::assertSentCount(1);
});

it('skips a channel that versions.json does not list yet', function () {
    updateChannelTestUse('nightly', preview: null, nightly: '4.6.0-alpha.0.20261027.1');

    expect(fetch_latest_versions(tries: 1)['upgrade_script_url'])->toBe('https://cdn.example.com/coolify/nightly/upgrade.sh');
});

it('checks nothing when versions.json does not answer', function () {
    InstanceSettings::findOrFail(0)->update(['update_channel' => 'nightly']);
    Once::flush();
    Http::fake(['cdn.example.com/coolify/versions.json' => Http::response('', 404)]);

    expect(fetch_latest_versions(tries: 1))->toBeNull();
});

it('upgrades with the upgrade script of the channel of the newest version', function () {
    updateChannelTestUse('nightly');

    (new UpdateCoolify)->handle(manual_update: true);

    expect(RemoteProcessCommand::read(Activity::query()->latest('id')->first()))->toBe(
        "curl -fsSL https://cdn.example.com/coolify/preview/upgrade.sh -o /data/coolify/source/upgrade.sh\n".
        "bash /data/coolify/source/upgrade.sh '4.6.0-beta.2' '1.0.19' 'docker.io'"
    );
});

it('upgrades to the custom image from ghcr.io only when asked', function () {
    updateChannelTestUse('custom', 'pr-1234');

    (new UpdateCoolify)->handle();
    expect(Activity::query()->count())->toBe(0);

    (new UpdateCoolify)->handle(manual_update: true);
    expect(RemoteProcessCommand::read(Activity::query()->latest('id')->first()))->toBe(
        "curl -fsSL https://cdn.example.com/coolify/upgrade.sh -o /data/coolify/source/upgrade.sh\n".
        "bash /data/coolify/source/upgrade.sh 'pr-1234' '1.0.19' 'ghcr.io'"
    );
});

it('reports no new version while a custom image is pinned', function () {
    updateChannelTestUse('custom', 'pr-1234');

    (new CheckForUpdatesJob)->handle();

    expect(InstanceSettings::findOrFail(0)->new_version_available)->toBeFalsy();
});

it('treats an image tag that names no version as reached once the upgrade completes', function () {
    expect(CoolifyUpgradeStatus::hasReachedTargetVersion('4.6.0-beta.1-pr.1234', 'pr-1234'))->toBeTrue()
        ->and(CoolifyUpgradeStatus::hasReachedTargetVersion('4.5.0', '4.5.1'))->toBeFalse();
});

it('accepts only an image tag for the custom image', function (?string $tag, bool $valid) {
    $user = User::factory()->create();
    Team::findOrFail(0)->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    $component = Livewire::test(Updates::class)
        ->set('update_channel', 'custom')
        ->set('update_custom_tag', $tag)
        ->call('submit');

    $valid ? $component->assertHasNoErrors('update_custom_tag') : $component->assertHasErrors('update_custom_tag');
})->with([
    'pull request' => ['pr-1234', true],
    'version' => ['4.6.0-beta.1', true],
    'missing' => [null, false],
    'shell' => ['pr-1; touch /tmp/pwned', false],
    'leading dash' => ['-pr', false],
]);

it('warns before an instance switches to nightly or a custom image', function (string $channel, string $warning) {
    $user = User::factory()->create();
    Team::findOrFail(0)->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);

    Livewire::test(Updates::class)
        ->assertDontSee($warning)
        ->set('update_channel', $channel)
        ->assertSee($warning);
})->with([
    'nightly' => ['nightly', 'Nightly builds can break this instance'],
    'custom image' => ['custom', 'A custom image can break this instance'],
]);

it('keeps the nightlies of the running version after a switch to stable until its stable release', function (string $stable, string $nightly, string $version) {
    config(['constants.coolify.version' => '4.6.0-beta.1.20261027.3']);
    InstanceSettings::findOrFail(0)->update(['update_channel' => 'stable', 'previous_update_channel' => 'nightly']);
    Once::flush();
    Http::fake([
        'cdn.example.com/coolify/versions.json' => Http::response(['stable' => ['coolify' => ['version' => $stable]], 'nightly' => ['coolify' => ['version' => $nightly]]]),
    ]);

    expect(data_get(fetch_latest_versions(), 'versions.coolify.version'))->toBe($version);
})->with([
    'a newer nightly of 4.6.0' => ['4.5.1', '4.6.0-beta.2.20261028.1', '4.6.0-beta.2.20261028.1'],
    'the stable release of 4.6.0' => ['4.6.0', '4.7.0-alpha.0.20261101.1', '4.6.0'],
    'no nightly of 4.7.0 before 4.6.0 is stable' => ['4.5.1', '4.7.0-alpha.0.20261101.1', '4.5.1'],
]);

it('follows the channel it left after a switch to a more stable channel', function (string $from, string $to, ?string $followed) {
    $user = User::factory()->create();
    Team::findOrFail(0)->members()->attach($user->id, ['role' => 'admin']);
    $this->actingAs($user);
    session(['currentTeam' => ['id' => 0]]);
    updateChannelTestUse($from);

    $component = new class extends Updates
    {
        protected function syncRegistryUrlToEnv(string $registryUrl): void {}
    };
    $component->settings = InstanceSettings::findOrFail(0);
    $component->auto_update_frequency = '0 0 * * *';
    $component->update_check_frequency = '0 * * * *';
    $component->is_auto_update_enabled = true;
    $component->docker_registry_url = 'docker.io';
    $component->update_channel = $to;
    $component->update_custom_tag = $to === 'custom' ? 'pr-1234' : null;
    $component->instantSave();

    expect(InstanceSettings::findOrFail(0)->previous_update_channel)->toBe($followed);
})->with([
    'nightly to stable' => ['nightly', 'stable', 'nightly'],
    'nightly to preview' => ['nightly', 'preview', 'nightly'],
    'preview to stable' => ['preview', 'stable', 'preview'],
    'stable to nightly' => ['stable', 'nightly', null],
    'nightly to a custom image' => ['nightly', 'custom', null],
]);

it('switches the update channel with app:update-channel like the update page does', function (string $from, string $to, ?string $followed) {
    InstanceSettings::findOrFail(0)->update(['update_channel' => $from]);
    Once::flush();

    $this->artisan('app:update-channel', ['channel' => $to])->assertSuccessful();

    expect(InstanceSettings::findOrFail(0))
        ->update_channel->toBe($to)
        ->previous_update_channel->toBe($followed);
})->with([
    'stable to nightly' => ['stable', 'nightly', null],
    'nightly to preview' => ['nightly', 'preview', 'nightly'],
]);

it('refuses an update channel that does not exist', function () {
    $this->artisan('app:update-channel', ['channel' => 'custom'])->assertFailed();

    expect(InstanceSettings::findOrFail(0)->update_channel)->toBe('stable');
});

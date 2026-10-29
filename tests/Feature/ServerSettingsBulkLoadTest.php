<?php

use App\Jobs\DockerCleanupJob;
use App\Jobs\ScheduledJobManager;
use App\Jobs\ServerManagerJob;
use App\Jobs\ServerPatchCheckJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Cache::flush();
    config(['constants.coolify.self_hosted' => true]);
    InstanceSettings::forceCreate(['id' => 0, 'instance_timezone' => 'UTC']);
    $this->team = Team::factory()->create();
    $this->patchResult = ['package_manager' => 'apt', 'total_updates' => 1, 'updates' => [['package' => 'openssl', 'new_version' => '3.0.13-2']]];
});

afterEach(function () {
    Carbon::setTestNow();
});

function createBulkLoadServer(Team $team, array $settings): Server
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->forceFill([
        'is_reachable' => true,
        'is_usable' => true,
        'server_timezone' => 'UTC',
        ...$settings,
    ])->saveQuietly();

    return $server;
}

/**
 * @return list<string>
 */
function serverSettingsQueriesDuring(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'from "server_settings"')) {
            $queries[] = $query->sql;
        }
    });
    $callback();

    return $queries;
}

it('loads every settings column except the patch result and matches each settings row to its server', function () {
    $first = createBulkLoadServer($this->team, ['docker_cleanup_frequency' => '0 1 * * *', 'patch_check_result' => $this->patchResult]);
    $second = createBulkLoadServer($this->team, ['docker_cleanup_frequency' => '0 2 * * *', 'patch_check_frequency' => 'daily']);

    $servers = Server::query()
        ->whereKey([$first->id, $second->id])
        ->with(['settings' => fn (HasOne $query) => $query->withoutPatchCheckResult()])
        ->get()
        ->keyBy('id');

    foreach ([$first, $second] as $server) {
        $full = ServerSetting::query()->where('server_id', $server->id)->firstOrFail()->getAttributes();
        unset($full['patch_check_result']);

        expect($servers[$server->id]->settings->getAttributes())->toBe($full);
    }
    expect($servers[$first->id]->settings->docker_cleanup_frequency)->toBe('0 1 * * *')
        ->and($servers[$second->id]->settings->docker_cleanup_frequency)->toBe('0 2 * * *')
        ->and($servers[$second->id]->settings->patch_check_frequency)->toBe('daily')
        ->and($servers[$first->id]->settings->is_reachable)->toBeTrue();
});

it('does not read the patch results of all servers in the server manager, and its jobs still get them', function () {
    Queue::fake();
    Carbon::setTestNow(Carbon::create(2026, 10, 28, 12, 0, 0, 'UTC'));
    $server = createBulkLoadServer($this->team, ['patch_check_frequency' => '* * * * *', 'patch_check_result' => $this->patchResult]);

    $queries = serverSettingsQueriesDuring(fn () => (new ServerManagerJob)->handle());

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('patch_check_result')->not->toContain('*');
    }

    Queue::assertPushed(ServerPatchCheckJob::class, function (ServerPatchCheckJob $job) use ($server) {
        $restored = unserialize(serialize($job));

        return $restored->server->id === $server->id
            && $restored->server->settings->patch_check_result === $this->patchResult;
    });
});

it('does not read the patch results of all servers when it schedules Docker cleanups', function () {
    Queue::fakeExcept(ScheduledJobManager::class);
    Carbon::setTestNow(Carbon::create(2026, 10, 28, 12, 0, 0, 'UTC'));
    $server = createBulkLoadServer($this->team, ['docker_cleanup_frequency' => '* * * * *', 'patch_check_result' => $this->patchResult]);

    $queries = serverSettingsQueriesDuring(fn () => (new ScheduledJobManager)->handle());

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, '"server_id" in')))->not->toBeEmpty();
    foreach ($queries as $sql) {
        if (str_contains($sql, '"server_id" in')) {
            expect($sql)->not->toContain('patch_check_result')->not->toContain('*');
        }
    }
    Queue::assertPushed(DockerCleanupJob::class, fn (DockerCleanupJob $job) => $job->server->id === $server->id);
});

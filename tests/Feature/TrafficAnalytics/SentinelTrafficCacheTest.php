<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

class CountingTrafficClient extends SentinelTrafficClient
{
    public int $calls = 0;

    protected function remoteFetch(string $url): string
    {
        $this->calls++;

        return '{"requests":0}';
    }
}

it('caches identical requests within the TTL', function () {
    Cache::flush();
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $client = new CountingTrafficClient($server);
    $client->overview('app', 'a', 'b');
    $client->overview('app', 'a', 'b');
    expect($client->calls)->toBe(1);
});

it('surfaces the real remote error instead of a TypeError when the fetch fails', function (int $exitCode, string $output, string $errorOutput, string $message) {
    Cache::flush();
    config()->set('constants.ssh.max_retries', 1);
    Process::fake(fn () => Process::result(output: $output, errorOutput: $errorOutput, exitCode: $exitCode));
    $team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    // Only a functional server is queried.
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    expect(fn () => (new SentinelTrafficClient($server))->overview('app', 'a', 'b'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'failed docker exec' => [1, '', 'Error response from daemon: No such container: coolify-sentinel', 'No such container: coolify-sentinel'],
    'literal null body' => [0, 'null', '', 'Traffic analytics returned an invalid response.'],
]);

it('keeps the range window stable within the 60s cache interval so repeated loads hit the cache', function () {
    Cache::flush();
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $client = new CountingTrafficClient($server);

    $this->travelTo(Carbon::parse('2026-09-30 10:00:05', 'UTC'));
    [$from, $to] = SentinelTrafficClient::rangeWindow('24h');
    $client->overview('app', $from, $to);

    $this->travelTo(Carbon::parse('2026-09-30 10:00:47', 'UTC'));
    [$laterFrom, $laterTo] = SentinelTrafficClient::rangeWindow('24h');
    $client->overview('app', $laterFrom, $laterTo);

    expect($client->calls)->toBe(1)
        ->and([$laterFrom, $laterTo])->toBe([$from, $to])
        // The window still reaches "now" and spans exactly the requested range.
        ->and($to)->toBe('2026-09-30T10:01:00Z')
        ->and($from)->toBe('2026-09-29T10:01:00Z');

    $this->travelTo(Carbon::parse('2026-09-30 10:01:03', 'UTC'));
    [, $nextTo] = SentinelTrafficClient::rangeWindow('7d');
    expect($nextTo)->toBe('2026-09-30T10:02:00Z')
        ->and(SentinelTrafficClient::rangeWindow('7d')[0])->toBe('2026-09-23T10:02:00Z');
});

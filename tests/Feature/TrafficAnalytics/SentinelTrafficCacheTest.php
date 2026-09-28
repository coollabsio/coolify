<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    expect(fn () => (new SentinelTrafficClient($server))->overview('app', 'a', 'b'))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'failed docker exec' => [1, '', 'Error response from daemon: No such container: coolify-sentinel', 'No such container: coolify-sentinel'],
    'literal null body' => [0, 'null', '', 'Traffic analytics returned an invalid response.'],
]);

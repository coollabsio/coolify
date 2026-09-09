<?php

use App\Models\Server;
use App\Models\Team;
use App\Services\SentinelTrafficClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

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

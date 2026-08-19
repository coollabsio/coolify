<?php

use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('test-token', ['*'])->plainTextToken;

    // The logs endpoint resolves the server, which requires a reachable private
    // key — without it the request fails on a missing PrivateKey model.
    Storage::fake('ssh-keys');
    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) new Cuid2,
        'name' => 'test-project',
        'team_id' => $this->team->id,
    ]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->project->environments()->first()->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockercompose',
    ]);
});

function multiContainerLogsHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

/**
 * Fakes the SSH calls issued by the logs endpoint.
 *
 * @param  array<int, array{name: string, id: string, status: string, logs: string}>  $containers
 */
function fakeComposeContainers(array $containers): void
{
    $listing = collect($containers)
        ->map(fn (array $container) => json_encode([
            'ID' => $container['id'],
            'Names' => $container['name'],
            'Labels' => 'coolify.managed=true,coolify.pullRequestId=0',
        ]))
        ->implode("\n");

    Process::fake(function ($process) use ($containers, $listing) {
        $command = $process->command;

        if (str_contains($command, 'docker ps -a --filter')) {
            return Process::result(output: $listing);
        }

        foreach ($containers as $container) {
            if (str_contains($command, 'docker inspect') && str_contains($command, $container['name'])) {
                return Process::result(output: json_encode(['State' => ['Status' => $container['status']]]));
            }

            if (str_contains($command, 'docker logs') && str_contains($command, $container['id'])) {
                return Process::result(output: $container['logs']);
            }
        }

        return Process::result(output: '');
    });
}

describe('GET /api/v1/applications/{uuid}/logs', function () {
    test('returns the logs of every running container of a compose application', function () {
        // Regression: the endpoint used to read $containers->first() only, so
        // every service other than the first one was silently unreachable
        // through the API even though the UI showed a panel per container.
        fakeComposeContainers([
            ['name' => 'web-abc-1', 'id' => 'aaaa1111', 'status' => 'running', 'logs' => 'web service listening on 3000'],
            ['name' => 'worker-abc-1', 'id' => 'bbbb2222', 'status' => 'running', 'logs' => 'worker processing queue jobs'],
        ]);

        $response = $this->withHeaders(multiContainerLogsHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/logs");

        $response->assertOk();

        $logs = $response->json('logs');

        expect($logs)
            ->toContain('web service listening on 3000')
            ->toContain('worker processing queue jobs')
            ->toContain('===== web-abc-1 =====')
            ->toContain('===== worker-abc-1 =====');
    });

    test('keeps the payload untouched for single-container applications', function () {
        // Backward compatibility: no container-name header is added when there
        // is only one running container, so existing consumers keep parsing the
        // exact same string.
        fakeComposeContainers([
            ['name' => 'web-abc-1', 'id' => 'aaaa1111', 'status' => 'running', 'logs' => 'web service listening on 3000'],
        ]);

        $response = $this->withHeaders(multiContainerLogsHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/logs");

        $response->assertOk();
        expect($response->json('logs'))->toBe('web service listening on 3000');
    });

    test('skips exited containers instead of reporting the whole application as stopped', function () {
        // Regression: an exited one-shot container (migrations, seeders) listed
        // first made the endpoint answer 400 "Application is not running."
        // while the rest of the stack was healthy.
        fakeComposeContainers([
            ['name' => 'migrations-abc-1', 'id' => 'cccc3333', 'status' => 'exited', 'logs' => 'migrations done'],
            ['name' => 'web-abc-1', 'id' => 'aaaa1111', 'status' => 'running', 'logs' => 'web service listening on 3000'],
        ]);

        $response = $this->withHeaders(multiContainerLogsHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/logs");

        $response->assertOk();

        $logs = $response->json('logs');

        expect($logs)->toBe('web service listening on 3000')
            ->and($logs)->not->toContain('migrations done');
    });

    test('still reports a stopped application when no container is running', function () {
        fakeComposeContainers([
            ['name' => 'web-abc-1', 'id' => 'aaaa1111', 'status' => 'exited', 'logs' => 'crashed'],
        ]);

        $response = $this->withHeaders(multiContainerLogsHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$this->application->uuid}/logs");

        $response->assertStatus(400);
        expect($response->json('message'))->toBe('Application is not running.');
    });
});

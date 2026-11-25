<?php

use App\Enums\GithubRunnerStatus;
use App\Jobs\CleanupGithubRunnerJob;
use App\Jobs\DeregisterGithubRunnerJob;
use App\Jobs\ProvisionGithubRunnerJob;
use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ServerTransfer\ServerTransferClaimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // A claim writes the owner marker on the host over SSH.
    Process::fake();
    Storage::fake('ssh-keys');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true, 'fqdn' => 'https://coolify-a.test']);

    $this->team = Team::factory()->create();
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
        'ip' => '10.77.0.10',
        'name' => 'claim-me',
        'server_metadata' => [
            'transfer' => [
                'status' => 'imported',
                'export_id' => 'export-xyz',
            ],
        ],
    ]);
});

test('markTransferred writes force_disabled and transfer status together', function () {
    $result = app(ServerTransferClaimer::class)->markTransferred(
        $this->server,
        exportId: 'export-xyz',
        targetInstanceUrl: 'https://coolify-b.test',
    );

    expect($result['server_uuid'])->toBe($this->server->uuid);

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('transferred')
        ->and(data_get($this->server->server_metadata, 'transfer.export_id'))->toBe('export-xyz')
        ->and(data_get($this->server->server_metadata, 'transfer.target_instance_url'))->toBe('https://coolify-b.test')
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue()
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeFalse();
});

test('claim persists ownership metadata and always rebinds Sentinel', function () {
    $result = app(ServerTransferClaimer::class)->claim($this->server);

    expect($result['server_uuid'])->toBe($this->server->uuid)
        ->and($result)->not->toHaveKey('claim_written')
        ->and(data_get($result, 'claim.instance_url'))->toBe('https://coolify-a.test');

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('claimed')
        ->and(data_get($this->server->server_metadata, 'transfer.export_id'))->toBe('export-xyz')
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeTrue()
        ->and((string) $this->server->settings->sentinel_custom_url)->toBe('https://coolify-a.test');
});

test('claim writes the owner marker with its claim id on the host', function () {
    $result = app(ServerTransferClaimer::class)->claim($this->server);

    $claimId = data_get($result, 'claim.claim_id');
    expect($claimId)->toBeString()->not->toBeEmpty()
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.claim.claim_id'))->toBe($claimId);

    Process::assertRan(function ($process) use ($claimId) {
        $marker = json_decode((string) $process->input, true);

        return str_contains($process->command, ServerTransferClaimer::OWNER_MARKER_PATH)
            && data_get($marker, 'claim_id') === $claimId
            && data_get($marker, 'instance_url') === 'https://coolify-a.test';
    });
});

test('claim changes nothing when the owner marker cannot be written', function () {
    // The new Sentinel token restarts Sentinel; this test is about the marker.
    Queue::fake();
    Process::fake(['*' => Process::result(errorOutput: 'Permission denied', exitCode: 1)]);
    $sentinelUrlBefore = $this->server->settings->sentinel_custom_url;

    expect(fn () => app(ServerTransferClaimer::class)->claim($this->server))->toThrow(RuntimeException::class);

    $this->server->refresh();
    expect(data_get($this->server->server_metadata, 'transfer.status'))->toBe('imported')
        ->and(data_get($this->server->server_metadata, 'transfer.claim'))->toBeNull()
        ->and($this->server->settings->sentinel_custom_url)->toBe($sentinelUrlBefore);
});

test('a server is released when its owner marker names a newer claim of another instance', function () {
    app(ServerTransferClaimer::class)->claim($this->server);

    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), json_encode([
        'claim_id' => 'claim-of-instance-b',
        'instance_url' => 'https://8.8.8.8',
        'claimed_at' => now()->addMinute()->toIso8601String(),
    ]));

    $this->server->refresh();
    expect($released)->toBeTrue()
        ->and($this->server->isTransferredAway())->toBeTrue()
        ->and(data_get($this->server->server_metadata, 'transfer.target_instance_url'))->toBe('https://8.8.8.8')
        ->and(data_get($this->server->server_metadata, 'transfer.claimed_by_marker'))->toBeTrue()
        ->and((bool) $this->server->settings->force_disabled)->toBeTrue()
        ->and((bool) $this->server->settings->is_sentinel_enabled)->toBeFalse();
});

test('a server is not released when the owner marker is its own claim or missing', function (?string $marker) {
    $claimId = data_get(app(ServerTransferClaimer::class)->claim($this->server), 'claim.claim_id');
    $marker = $marker === 'own' ? json_encode(['claim_id' => $claimId, 'instance_url' => 'https://coolify-a.test']) : $marker;

    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), $marker);

    expect($released)->toBeFalse()
        ->and($this->server->fresh()->isTransferredAway())->toBeFalse()
        ->and((bool) $this->server->fresh()->settings->force_disabled)->toBeFalse();
})->with([
    'own claim' => 'own',
    'no marker' => '',
    'unreadable marker' => 'not json',
    'marker without claim id' => '{"instance_url":"https://coolify-b.test"}',
]);

test('a server that this instance never claimed ignores the owner marker', function () {
    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server, json_encode([
        'claim_id' => 'left-over-claim-of-a-deleted-instance',
        'instance_url' => 'https://coolify-b.test',
    ]));

    expect($released)->toBeFalse()
        ->and($this->server->fresh()->isTransferredAway())->toBeFalse();
});

test('markTransferred keeps the DNS records of the server resources as not owned, without provider calls', function () {
    Http::fake();
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $application = Application::factory()->create(['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()]);
    $otherApplication = Application::factory()->create();
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare']);
    $zone = DnsProviderZone::factory()->create(['integration_token_id' => $token->id]);
    $onlyThisServer = ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => $zone->id]);
    $onlyThisServer->addReference($application);
    $shared = ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => $zone->id]);
    $shared->addReference($application);
    $shared->addReference($otherApplication);

    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    Http::assertNothingSent();
    expect($onlyThisServer->fresh()->owned)->toBeFalse()
        ->and($onlyThisServer->fresh()->references()->pluck('resource_id')->all())->toBe([$application->id])
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.parked_dns_record_ids'))->toBe([$onlyThisServer->id])
        ->and($shared->fresh()->owned)->toBeTrue()
        ->and($shared->fresh()->references()->pluck('resource_id')->all())->toBe([$otherApplication->id]);
});

test('take back owns the parked DNS records again and starts the finish list', function (string $remoteComment, bool $reclaimed) {
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $application = Application::factory()->create(['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()]);
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare']);
    $zone = DnsProviderZone::factory()->create(['integration_token_id' => $token->id]);
    $record = ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => $zone->id, 'name' => 'app.example.test', 'type' => 'A', 'content' => '10.0.0.1']);
    $record->addReference($application);
    $claimer = app(ServerTransferClaimer::class);
    $claimer->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');
    $claimer->claim($this->server->fresh());
    $remote = ['type' => 'A', 'name' => 'app.example.test', 'content' => '10.0.0.1', 'comment' => str_replace('{uuid}', $record->uuid, $remoteComment)];
    Http::fake([
        'api.cloudflare.com/*' => fn ($request) => $request->method() === 'GET'
            ? Http::response(['success' => true, 'result' => $remote])
            : Http::response(['success' => true, 'result' => [...$remote, 'comment' => $record->ownershipComment()]]),
    ]);

    $items = $claimer->restoreAfterTakeBack($this->server->fresh());

    expect($record->fresh()->owned)->toBe($reclaimed)
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.parked_dns_record_ids'))->toBeNull()
        ->and(array_column($items, 'key'))->toContain("dns_record:{$record->uuid}", "manual_webhook:{$application->uuid}")
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.finish.items'))->toBe($items);
    if ($reclaimed) {
        Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['comment'] === $record->ownershipComment());
    } else {
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }
})->with([
    'the other instance adopted the record' => ['managed-by: coolify other-instance/{uuid}', true],
    'someone changed the record at the provider' => ['set by hand', false],
]);

function transferRunnerSetup(Server $server): array
{
    $githubApp = GithubApp::create([
        'name' => 'runner-app',
        'organization' => 'acme',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 5151,
        'installation_id' => 5252,
        'webhook_secret' => 'runner-secret',
        'team_id' => $server->team_id,
        'is_system_wide' => false,
    ]);
    $config = GithubRunnerConfig::create(['server_id' => $server->id, 'github_app_id' => $githubApp->id, 'labels' => ['coolify'], 'is_enabled' => true]);

    return [$githubApp, $config];
}

test('markTransferred disables the runner config and stops tracking the runners without removing them at GitHub', function () {
    Queue::fake();
    [$githubApp, $config] = transferRunnerSetup($this->server);
    $execution = fn (GithubRunnerStatus $status, array $attributes = []) => GithubRunnerExecution::create([
        'github_app_id' => $githubApp->id,
        'github_runner_config_id' => $config->id,
        'server_id' => $this->server->id,
        'trigger_workflow_job_id' => fake()->unique()->numberBetween(1000, 9999),
        'status' => $status,
        'runner_id' => fake()->unique()->numberBetween(1, 999),
        ...$attributes,
    ]);
    $active = collect([
        $execution(GithubRunnerStatus::Queued, ['runner_id' => null]),
        $execution(GithubRunnerStatus::Provisioning),
        $execution(GithubRunnerStatus::Idle),
        $execution(GithubRunnerStatus::Running),
    ]);
    $completed = $execution(GithubRunnerStatus::Completed, ['completed_at' => now()->subDay(), 'error_message' => null]);

    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    expect($config->fresh()->is_enabled)->toBeFalse()
        ->and($completed->fresh()->only(['status', 'error_message']))->toBe(['status' => GithubRunnerStatus::Completed, 'error_message' => null]);
    foreach ($active as $row) {
        $row->refresh();
        expect($row->status)->toBe(GithubRunnerStatus::Cancelled)
            ->and($row->completed_at)->not->toBeNull()
            ->and($row->error_message)->toBe('The server was transferred to another Coolify instance. This instance stopped managing its runners.');
    }

    // Deleting the archive later must not pull the runners that now belong to the target.
    $this->server->fresh()->forceDelete();

    Queue::assertNotPushed(DeregisterGithubRunnerJob::class);
    Queue::assertNotPushed(CleanupGithubRunnerJob::class);
});

test('a GitHub webhook does not queue a runner for a transferred server', function () {
    Queue::fake();
    [$githubApp] = transferRunnerSetup($this->server);
    $send = function () use ($githubApp) {
        $body = json_encode([
            'action' => 'queued',
            'workflow_job' => ['id' => fake()->unique()->numberBetween(1000, 9999), 'labels' => ['self-hosted', 'coolify'], 'name' => 'test'],
            'repository' => ['id' => 99, 'full_name' => 'acme/api'],
            'installation' => ['id' => $githubApp->installation_id],
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/webhooks/source/github/events', [], [], [], [
            'HTTP_X-GitHub-Event' => 'workflow_job',
            'HTTP_X-GitHub-Hook-Installation-Target-Id' => (string) $githubApp->app_id,
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'runner-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    };
    $send()->assertSee('Runner queued.');
    GithubRunnerExecution::query()->delete();

    app(ServerTransferClaimer::class)->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');

    $send()->assertSee('No runner configuration matches the job labels.');
    expect(GithubRunnerExecution::count())->toBe(0);
    Queue::assertPushed(ProvisionGithubRunnerJob::class, 1);
});

test('an owner marker that is not newer than the own claim, or has no http(s) URL, does not release the server', function (array $marker) {
    app(ServerTransferClaimer::class)->claim($this->server);
    $marker = ['claim_id' => 'forged-claim', 'instance_url' => 'https://8.8.8.8', 'claimed_at' => now()->addMinute()->toIso8601String(), ...$marker];

    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), json_encode($marker));

    $this->server->refresh();
    expect($released)->toBeFalse()
        ->and($this->server->isTransferredAway())->toBeFalse()
        ->and((bool) $this->server->settings->force_disabled)->toBeFalse()
        ->and(data_get($this->server->server_metadata, 'transfer.owner_marker_conflict.claim_id'))->toBe('forged-claim');
})->with([
    'older claim' => [['claimed_at' => now()->subDay()->toIso8601String()]],
    'no claim time' => [['claimed_at' => null]],
    'javascript URL' => [['instance_url' => 'javascript:alert(1)']],
    'no URL' => [['instance_url' => null]],
]);

test('a newer owner marker of a self-hosted target with a plain http URL releases the server, and the URL stays untrusted', function () {
    app(ServerTransferClaimer::class)->claim($this->server);
    $marker = ['claim_id' => 'target-claim', 'instance_url' => 'http://10.0.0.5:8000', 'claimed_at' => now()->addMinute()->toIso8601String()];

    $released = app(ServerTransferClaimer::class)->releaseIfClaimedElsewhere($this->server->fresh(), json_encode($marker));

    $this->server->refresh();
    expect($released)->toBeTrue()
        ->and($this->server->isTransferredAway())->toBeTrue()
        ->and(data_get($this->server->server_metadata, 'transfer.target_instance_url'))->toBe('http://10.0.0.5:8000')
        ->and(data_get($this->server->server_metadata, 'transfer.claimed_by_marker'))->toBeTrue();
});

test('claim gives Sentinel a new token', function () {
    $tokenBefore = $this->server->settings->sentinel_token;

    app(ServerTransferClaimer::class)->claim($this->server);

    expect($this->server->fresh()->settings->sentinel_token)->not->toBe($tokenBefore)->not->toBeEmpty();
});

test('applications that use the server only as an additional destination keep their DNS records and survive the archive', function () {
    Http::fake();
    Queue::fake();
    $otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->privateKey->id, 'ip' => '10.77.0.11']);
    $otherDestination = StandaloneDocker::where('server_id', $otherServer->id)->firstOrFail();
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $application = Application::factory()->create(['destination_id' => $otherDestination->id, 'destination_type' => $otherDestination->getMorphClass()]);
    $application->additional_servers()->attach($this->server->id, ['standalone_docker_id' => $destination->id]);
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare']);
    $record = ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => DnsProviderZone::factory()->create(['integration_token_id' => $token->id])->id]);
    $record->addReference($application);
    $claimer = app(ServerTransferClaimer::class);

    $claimer->markTransferred($this->server, targetInstanceUrl: 'https://coolify-b.test');
    expect($record->fresh()->owned)->toBeTrue()
        ->and(data_get($this->server->fresh()->server_metadata, 'transfer.parked_dns_record_ids'))->toBe([]);

    $claimer->deleteArchive($this->server->fresh());

    expect(Application::find($application->id))->not->toBeNull()
        ->and($application->additional_servers()->count())->toBe(0)
        ->and($record->fresh()->owned)->toBeTrue()
        ->and(Server::withTrashed()->find($this->server->id))->toBeNull();
});

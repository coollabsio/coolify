<?php

use App\Actions\Application\CleanupPreviewDeployment;
use App\Livewire\Source\Origin\Change;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\OriginApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0]);

    // Origin's own signing key: it signs webhook deliveries and installation receipts.
    $keyPair = sodium_crypto_sign_keypair();
    $this->originSecretKey = sodium_crypto_sign_secretkey($keyPair);
    Http::fake([
        'https://api.cursor.com/v1/origin/keys' => Http::response(['keys' => [[
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'alg' => 'EdDSA',
            'use' => 'sig',
            'kid' => 'origin-key-1',
            'x' => rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($keyPair)), '+/', '-_'), '='),
        ]]]),
        'https://api.cursor.com/v1/origin/app/installations/*' => Http::response(['id' => 'i_new', 'target' => ['slug' => 'acme']]),
    ]);

    $this->team = Team::factory()->create();
    $this->originApp = OriginApp::factory()->create([
        'team_id' => $this->team->id,
        'app_id' => 'app_coolify',
        'installation_id' => 'i_acme',
    ]);
});

function createOriginWebhookApplication(OriginApp $originApp, string $branch = 'main'): Application
{
    $project = Project::factory()->create(['team_id' => $originApp->team_id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $originApp->team_id]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    $destination = $server->standaloneDockers()->firstOrFail();

    return Application::create([
        'name' => 'origin-app',
        'git_repository' => 'acme/rocket',
        'git_branch' => $branch,
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'source_id' => $originApp->id,
        'source_type' => OriginApp::class,
    ]);
}

function sendOriginWebhook(string $type, array $payload, string $secretKey, array $overrides = []): TestResponse
{
    $deliveryId = $overrides['id'] ?? 'whd_'.Str::random(10);
    $timestamp = (string) ($overrides['timestamp'] ?? time());
    $body = json_encode([
        'deliveryId' => $deliveryId,
        'appId' => $overrides['body_app_id'] ?? 'app_coolify',
        'installationId' => 'i_acme',
        'event' => ['id' => 'evt_1', 'type' => $type, 'eventTime' => now()->toRfc3339String(), 'payload' => $payload],
    ]);
    $signature = base64_encode(sodium_crypto_sign_detached(hash('sha256', "{$deliveryId}.{$timestamp}.{$body}"), $secretKey));

    return test()->call('POST', '/webhooks/source/origin/events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_webhook-id' => $deliveryId,
        'HTTP_webhook-timestamp' => $timestamp,
        'HTTP_webhook-signature' => $overrides['signature'] ?? "v1ed,{$signature}",
        'HTTP_webhook-event-type' => $type,
        'HTTP_webhook-app-id' => 'app_coolify',
        'HTTP_webhook-installation-id' => 'i_acme',
    ], $body);
}

function originPushPayload(string $branch = 'main', string $sha = '9a41f0c3d2b8e7f6a5c4d3e2f1b0a9c8d7e6f5a4', string $message = 'Add telemetry'): array
{
    return [
        'repository' => ['id' => 'repo_1', 'name' => 'rocket', 'owner' => ['slug' => 'acme', 'type' => 'team']],
        'refUpdates' => [[
            'ref' => "refs/heads/{$branch}",
            'before' => str_repeat('0', 40),
            'after' => $sha,
            'created' => false,
            'deleted' => false,
            'headCommit' => ['sha' => $sha, 'message' => $message],
        ]],
    ];
}

function originPullRequestPayload(string $baseBranch = 'main'): array
{
    return [
        'repository' => ['id' => 'repo_1', 'name' => 'rocket', 'owner' => ['slug' => 'acme', 'type' => 'team']],
        'pullRequest' => [
            'number' => '17',
            'draft' => false,
            'title' => 'Add telemetry',
            'head' => ['ref' => 'add-telemetry', 'sha' => '9a41f0c3d2b8e7f6a5c4d3e2f1b0a9c8d7e6f5a4'],
            'base' => ['ref' => $baseBranch, 'sha' => '3b1f9c2d8a7e6f5049c8b7a6d5e4f3a2b1c0d9e8'],
            'webUrl' => 'https://cursor.com/codebase/acme/rocket/pull/17',
        ],
    ];
}

function originInstallationReceipt(string $secretKey, array $claims = [], array $headers = []): string
{
    $now = CarbonImmutable::now()->startOfSecond();
    $builder = (new Builder(new JoseEncoder, ChainedFormatter::default()))
        ->withHeader('typ', $headers['typ'] ?? 'origin-installation-receipt+jwt')
        ->withHeader('kid', 'origin-key-1')
        ->issuedBy($claims['iss'] ?? 'https://api.cursor.com/v1/origin')
        ->permittedFor($claims['aud'] ?? 'app_coolify')
        ->relatedTo($claims['sub'] ?? 'i_new')
        ->issuedAt($now)
        ->expiresAt($claims['exp'] ?? $now->addMinutes(5))
        ->withClaim('namespace_id', 'ns_1');

    if (array_key_exists('state', $claims)) {
        $builder = $builder->withClaim('state', $claims['state']);
    }

    return $builder->getToken(new Eddsa, InMemory::plainText($secretKey))->toString();
}

describe('Origin webhook deliveries', function () {
    test('a signed push deploys applications on the pushed branch', function () {
        $application = createOriginWebhookApplication($this->originApp);
        $otherBranch = createOriginWebhookApplication($this->originApp, 'develop');

        sendOriginWebhook('repository.pushed', originPushPayload(), $this->originSecretKey)->assertOk();

        $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->sole();
        expect($deployment->commit)->toBe('9a41f0c3d2b8e7f6a5c4d3e2f1b0a9c8d7e6f5a4')
            ->and(ApplicationDeploymentQueue::where('application_id', $otherBranch->id)->exists())->toBeFalse();
    });

    test('rejects deliveries with a forged signature or an old timestamp', function (array $overrides) {
        createOriginWebhookApplication($this->originApp);

        sendOriginWebhook('repository.pushed', originPushPayload(), $this->originSecretKey, $overrides)->assertUnauthorized();

        expect(ApplicationDeploymentQueue::count())->toBe(0);
    })->with([
        'forged signature' => [['signature' => 'v1ed,'.base64_encode(str_repeat('a', 64))]],
        'signed by another key' => fn () => ['signature' => 'v1ed,'.base64_encode(sodium_crypto_sign_detached('x', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())))],
        'old timestamp' => [['timestamp' => time() - 600]],
    ]);

    test('a body that does not match the routing headers is rejected', function () {
        createOriginWebhookApplication($this->originApp);

        sendOriginWebhook('repository.pushed', originPushPayload(), $this->originSecretKey, ['body_app_id' => 'app_other'])->assertStatus(400);

        expect(ApplicationDeploymentQueue::count())->toBe(0);
    });

    test('a redelivery with the same webhook-id deploys only once', function () {
        createOriginWebhookApplication($this->originApp);

        sendOriginWebhook('repository.pushed', originPushPayload(), $this->originSecretKey, ['id' => 'whd_same'])->assertOk();
        sendOriginWebhook('repository.pushed', originPushPayload(), $this->originSecretKey, ['id' => 'whd_same'])->assertOk();

        expect(ApplicationDeploymentQueue::count())->toBe(1);
    });

    test('a push with [skip ci] in the commit message does not deploy', function () {
        createOriginWebhookApplication($this->originApp);

        sendOriginWebhook('repository.pushed', originPushPayload(message: 'Docs [skip ci]'), $this->originSecretKey)->assertOk();

        expect(ApplicationDeploymentQueue::count())->toBe(0);
    });

    test('a push does not deploy applications of another Origin installation', function () {
        $otherSource = OriginApp::factory()->create(['team_id' => $this->team->id, 'app_id' => 'app_coolify', 'installation_id' => 'i_other']);
        createOriginWebhookApplication($otherSource);

        sendOriginWebhook('repository.pushed', originPushPayload(), $this->originSecretKey)->assertOk();

        expect(ApplicationDeploymentQueue::count())->toBe(0);
    });

    test('a pull request opens a preview and queues its deployment', function () {
        $application = createOriginWebhookApplication($this->originApp);
        $application->settings->update(['is_preview_deployments_enabled' => true]);

        sendOriginWebhook('pull_request.created', originPullRequestPayload(), $this->originSecretKey)->assertOk();

        $preview = ApplicationPreview::where('application_id', $application->id)->sole();
        $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->sole();
        expect($preview->pull_request_id)->toBe(17)
            ->and($preview->git_type)->toBe('origin')
            ->and($deployment->pull_request_id)->toBe(17)
            ->and($deployment->git_type)->toBe('origin');
    });

    test('a merged pull request cleans up its preview', function () {
        $application = createOriginWebhookApplication($this->originApp);
        ApplicationPreview::create(['application_id' => $application->id, 'pull_request_id' => 17, 'git_type' => 'origin', 'pull_request_html_url' => '']);
        CleanupPreviewDeployment::shouldRun()->once();

        sendOriginWebhook('pull_request.merged', originPullRequestPayload(), $this->originSecretKey)->assertOk();
    });

    test('installation.deleted disconnects the source', function () {
        sendOriginWebhook('installation.deleted', ['installation' => ['id' => 'i_acme']], $this->originSecretKey)->assertOk();

        expect($this->originApp->refresh()->installation_id)->toBeNull();
    });
});

describe('Origin install callback', function () {
    beforeEach(function () {
        $this->originApp->update(['installation_id' => null]);
        $this->owner = User::factory()->create();
        $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);
        Cache::put(Change::installStateCacheKey('state-1'), ['origin_app_id' => $this->originApp->id, 'team_id' => $this->team->id], now()->addHour());
    });

    test('a verified receipt stores the installation', function () {
        $receipt = originInstallationReceipt($this->originSecretKey, ['state' => 'state-1']);

        $this->get('/webhooks/source/origin/install?installation_receipt='.$receipt)
            ->assertRedirect(route('source.origin.show', ['origin_app_uuid' => $this->originApp->uuid]));

        expect($this->originApp->refresh())
            ->installation_id->toBe('i_new')
            ->namespace_slug->toBe('acme');
    });

    test('rejects receipts that Coolify cannot trust', function (array $claims, array $headers = [], bool $foreignKey = false) {
        $key = $foreignKey ? sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()) : $this->originSecretKey;

        $this->get('/webhooks/source/origin/install?installation_receipt='.originInstallationReceipt($key, $claims, $headers))->assertRedirect();

        expect($this->originApp->refresh()->installation_id)->toBeNull();
    })->with([
        'unknown state' => [['state' => 'state-unknown']],
        'no state' => [[]],
        'another app' => [['state' => 'state-1', 'aud' => 'app_other']],
        'another issuer' => [['state' => 'state-1', 'iss' => 'https://evil.example.com']],
        'expired' => [['state' => 'state-1', 'exp' => CarbonImmutable::now()->subMinute()]],
        'wrong type' => [['state' => 'state-1'], ['typ' => 'JWT']],
        'not signed by Origin' => [['state' => 'state-1'], [], true],
    ]);

    test('a state of another team is rejected', function () {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->owner->id, ['role' => 'owner']);
        session(['currentTeam' => $otherTeam]);

        $this->get('/webhooks/source/origin/install?installation_receipt='.originInstallationReceipt($this->originSecretKey, ['state' => 'state-1']))
            ->assertRedirect(route('source.all'));

        expect($this->originApp->refresh()->installation_id)->toBeNull();
    });

    test('a member cannot connect the source', function () {
        $member = User::factory()->create();
        $this->team->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        $this->get('/webhooks/source/origin/install?installation_receipt='.originInstallationReceipt($this->originSecretKey, ['state' => 'state-1']))
            ->assertRedirect(route('source.all'));

        expect($this->originApp->refresh()->installation_id)->toBeNull();
    });
});

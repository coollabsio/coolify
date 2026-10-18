<?php

use App\Livewire\Server\Show;
use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Process::fake();
    Storage::fake('ssh-keys');
    InstanceSettings::forceCreate(['id' => 0, 'fqdn' => 'https://coolify-b.test']);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();

    $rsaKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsaKey, $pem);
    $this->githubApp = GithubApp::create([
        'name' => 'deploy-bot', 'organization' => 'acme', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'app_id' => 1234, 'installation_id' => 5678, 'webhook_secret' => 'gh-secret', 'is_system_wide' => false, 'is_public' => false,
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::create(['name' => 'gh', 'private_key' => $pem, 'team_id' => $this->team->id])->id,
    ]);
    $this->checklist = app(TransferFinishChecklist::class);
});

function finishTestApplication(object $test, array $attributes = []): Application
{
    return Application::factory()->create([
        'destination_id' => $test->destination->id,
        'destination_type' => $test->destination->getMorphClass(),
        ...$attributes,
    ]);
}

function finishTestItem(Server $server, string $key): array
{
    return collect(data_get($server->fresh()->server_metadata, 'transfer.finish.items'))->firstWhere('key', $key);
}

test('start lists a step for every kind of setting the server uses', function () {
    $githubApplication = finishTestApplication($this, ['source_type' => GithubApp::class, 'source_id' => $this->githubApp->id, 'fqdn' => 'https://shop.example.test']);
    $gitlabApp = GitlabApp::create(['name' => 'gitlab', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com', 'team_id' => $this->team->id, 'is_system_wide' => false]);
    finishTestApplication($this, ['source_type' => GitlabApp::class, 'source_id' => $gitlabApp->id]);
    $publicApplication = finishTestApplication($this, ['source_type' => null, 'source_id' => null, 'git_repository' => 'https://github.com/acme/public']);
    $token = IntegrationToken::factory()->for($this->team)->create(['provider' => 'cloudflare']);
    $record = ManagedDnsRecord::factory()->owned()->create(['dns_provider_zone_id' => DnsProviderZone::factory()->create(['integration_token_id' => $token->id])->id]);
    $record->addReference($githubApplication);
    $runnerConfig = GithubRunnerConfig::create(['server_id' => $this->server->id, 'github_app_id' => $this->githubApp->id, 'labels' => ['coolify']]);

    $keys = array_column($this->checklist->start($this->server), 'key');

    expect($keys)->toContain(
        'server_connection:server',
        'sentinel:server',
        "github_app_webhook:{$this->githubApp->uuid}",
        "github_app_urls:{$this->githubApp->uuid}",
        "gitlab_webhook:{$gitlabApp->uuid}",
        "manual_webhook:{$publicApplication->uuid}",
        "dns_record:{$record->uuid}",
        'domain:shop.example.test',
        "github_runners:{$runnerConfig->uuid}",
    )->and($keys)->not->toContain("manual_webhook:{$githubApplication->uuid}");
});

test('the GitHub App webhook step checks the URL at GitHub and sets it with one click', function () {
    finishTestApplication($this, ['source_type' => GithubApp::class, 'source_id' => $this->githubApp->id]);
    $this->checklist->start($this->server);
    $key = "github_app_webhook:{$this->githubApp->uuid}";
    $hookUrl = 'https://coolify-a.test/webhooks/source/github/events';
    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/hook/config' => function ($request) use (&$hookUrl) {
            if ($request->method() === 'PATCH') {
                $hookUrl = $request['url'];
            }

            return Http::response(['url' => $hookUrl, 'content_type' => 'json']);
        },
    ]);

    expect($this->checklist->check($this->server, $key)['status'])->toBe('action');

    $item = $this->checklist->fix($this->server, $key);

    expect($item['status'])->toBe('ok')
        ->and(finishTestItem($this->server, $key)['status'])->toBe('ok');
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['url'] === 'https://coolify-b.test/webhooks/source/github/events');
});

test('a webhook step turns OK when this instance receives a webhook after the transfer', function () {
    $application = finishTestApplication($this, ['source_type' => null, 'source_id' => null, 'git_repository' => 'https://github.com/acme/public']);
    $this->checklist->start($this->server);
    $key = "manual_webhook:{$application->uuid}";

    expect($this->checklist->check($this->server, $key)['status'])->toBe('action');

    $this->travel(1)->minutes();
    TransferFinishChecklist::recordWebhook('application', $application->uuid);

    expect($this->checklist->check($this->server, $key)['status'])->toBe('ok');
});

test('a verified GitHub App webhook is recorded for the finish list', function () {
    $body = json_encode(['action' => 'created', 'installation' => ['id' => 5678]]);
    $this->call('POST', '/webhooks/source/github/events', [], [], [], [
        'HTTP_X-GitHub-Event' => 'installation',
        'HTTP_X-GitHub-Hook-Installation-Target-Id' => '1234',
        'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'gh-secret'),
        'CONTENT_TYPE' => 'application/json',
    ], $body);

    expect(Cache::get("transfer-finish:webhook:github_app:{$this->githubApp->uuid}"))->not->toBeNull();
});

test('I finished closes the list and keeps the open steps as skipped', function () {
    $this->checklist->start($this->server);
    $this->checklist->markDone($this->server, 'server_connection:server');

    $this->checklist->finish($this->server->fresh(), $this->user);

    $finish = $this->checklist->of($this->server->fresh());
    expect($finish['finished_by'])->toBe($this->user->id)
        ->and($finish['skipped'])->toBe(['sentinel:server'])
        ->and($this->checklist->isOpen($this->server->fresh()))->toBeFalse();
});

test('the server page shows the open steps and the user can mark them done and finish', function () {
    $this->checklist->start($this->server);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->assertSee('Finish the transfer')
        ->assertSee('Sentinel sends its data to this instance')
        ->call('markTransferStepDone', 'sentinel:server')
        ->call('finishTransfer')
        ->assertDontSee('Finish the transfer');

    expect(finishTestItem($this->server, 'sentinel:server')['status'])->toBe('done');
});

test('team members cannot check or finish the transfer', function () {
    $this->checklist->start($this->server);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])->call('finishTransfer')->assertForbidden();
    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])->call('markTransferStepDone', 'sentinel:server')->assertForbidden();

    expect($this->checklist->isOpen($this->server->fresh()))->toBeTrue();
});

test('a Docker image application has no manual webhook step', function () {
    $application = finishTestApplication($this, ['source_type' => null, 'source_id' => null, 'build_pack' => 'dockerimage', 'git_repository' => 'coollabsio/coolify']);

    expect(array_column($this->checklist->start($this->server), 'key'))->not->toContain("manual_webhook:{$application->uuid}");
});

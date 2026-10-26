<?php

use App\Events\TransferFinishChanged;
use App\Jobs\CheckTransferFinishStepsJob;
use App\Livewire\Server\TransferFinish;
use App\Models\Application;
use App\Models\DnsProviderZone;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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
        ->and($finish['skipped'])->toBe(['sentinel:server', 'previous_ssh_key:server'])
        ->and($this->checklist->isOpen($this->server->fresh()))->toBeFalse();
});

test('the panel shows the open steps by group, collapses done steps, and finishes', function () {
    $this->checklist->start($this->server);

    Livewire::test(TransferFinish::class, ['server' => $this->server])
        ->assertSee('Finish the transfer')
        ->assertSee('0 of 3 done.')
        ->assertSee('Server')
        ->assertSee('Restart Sentinel')
        ->call('markStepDone', 'sentinel:server')
        ->assertSee('1 of 3 done.')
        ->assertSee('1 step is OK or done')
        ->assertSee('Finish with open steps')
        ->call('markStepDone', 'server_connection:server')
        ->call('markStepDone', 'previous_ssh_key:server')
        ->assertDontSee('Finish with open steps')
        ->call('finishTransfer')
        ->assertDontSee('Finish the transfer');

    expect(finishTestItem($this->server, 'sentinel:server')['status'])->toBe('done');
});

test('the panel starts background checks when they are stale, and not again within 30 seconds', function () {
    Queue::fake();
    $this->checklist->start($this->server);
    $component = Livewire::test(TransferFinish::class, ['server' => $this->server]);

    $component->call('refreshSteps');
    Queue::assertPushed(CheckTransferFinishStepsJob::class, 1);

    $this->checklist->checkAll($this->server->fresh());
    $component->call('refreshSteps');
    Queue::assertPushed(CheckTransferFinishStepsJob::class, 1);
});

test('a failed check shows a short message and keeps the technical reason as details', function () {
    finishTestApplication($this, ['source_type' => GithubApp::class, 'source_id' => $this->githubApp->id]);
    $this->checklist->start($this->server);
    Http::fake([
        'https://api.github.com/zen' => Http::response('ok', 200, ['Date' => now()->toRfc7231String()]),
        'https://api.github.com/app/hook/config' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    $item = $this->checklist->check($this->server, "github_app_webhook:{$this->githubApp->uuid}");

    expect($item['message'])->toBe('GitHub cannot be asked with the App key. No webhook arrived since the transfer.')
        ->and($item['error'])->toContain('Bad credentials')
        ->and($item['hint'])->toBe('GitHub must send webhooks to this instance.')
        ->and($item['details'][0]['value'])->toBe('https://coolify-b.test/webhooks/source/github/events');
});

test('team members cannot check or finish the transfer', function () {
    $this->checklist->start($this->server);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(TransferFinish::class, ['server' => $this->server])->call('finishTransfer')->assertForbidden();
    Livewire::test(TransferFinish::class, ['server' => $this->server])->call('markStepDone', 'sentinel:server')->assertForbidden();
    Livewire::test(TransferFinish::class, ['server' => $this->server])->call('fixStep', 'sentinel:server')->assertForbidden();

    expect($this->checklist->isOpen($this->server->fresh()))->toBeTrue();
});

test('a Docker image application has no manual webhook step', function () {
    $application = finishTestApplication($this, ['source_type' => null, 'source_id' => null, 'build_pack' => 'dockerimage', 'git_repository' => 'coollabsio/coolify']);

    expect(array_column($this->checklist->start($this->server), 'key'))->not->toContain("manual_webhook:{$application->uuid}");
});

test('an application that lost its system-wide git source gets a git source step with a link', function () {
    $environment = Project::factory()->create(['team_id' => $this->team->id])->environments()->first();
    $application = finishTestApplication($this, ['source_type' => null, 'source_id' => null, 'environment_id' => $environment->id]);
    $this->server->forceFill(['server_metadata' => ['transfer' => ['status' => 'claimed', 'git_source_applications' => [$application->uuid]]]])->save();
    $key = "git_source:{$application->uuid}";

    $items = collect($this->checklist->start($this->server));
    $item = $items->firstWhere('key', $key);
    expect($items->pluck('key'))->not->toContain("manual_webhook:{$application->uuid}")
        ->and($item['link'])->toStartWith('/project/')->toEndWith("/application/{$application->uuid}/source")
        ->and($this->checklist->check($this->server, $key)['status'])->toBe('action');

    $gitlabApp = GitlabApp::create(['name' => 'gitlab', 'api_url' => 'https://gitlab.com/api/v4', 'html_url' => 'https://gitlab.com', 'team_id' => $this->team->id, 'is_system_wide' => false]);
    $application->forceFill(['source_type' => GitlabApp::class, 'source_id' => $gitlabApp->id])->save();

    expect($this->checklist->check($this->server, $key)['status'])->toBe('ok');
});

test('restarting Sentinel waits for its data instead of failing the step at once', function () {
    $this->checklist->start($this->server);

    Livewire::test(TransferFinish::class, ['server' => $this->server])
        ->call('fixStep', 'sentinel:server')
        ->assertDispatched('info')
        ->assertNotDispatched('error');

    expect(finishTestItem($this->server, 'sentinel:server')['status'])->toBe('pending');
});

test('a changed step is broadcast to the team, and the open panel shows it without a reload', function () {
    Event::fake([TransferFinishChanged::class]);
    $this->checklist->start($this->server);
    $component = Livewire::test(TransferFinish::class, ['server' => $this->server])->assertSee('0 of 3 done.');

    $this->checklist->markDone($this->server->fresh(), 'sentinel:server');

    Event::assertDispatched(TransferFinishChanged::class, fn ($event) => $event->serverUuid === $this->server->uuid && $event->teamId === $this->team->id);
    $component->call('handleTransferFinishChanged', ['serverUuid' => $this->server->uuid])->assertSee('1 of 3 done.');
});

test('the GitHub App steps link to the App settings at GitHub', function () {
    finishTestApplication($this, ['source_type' => GithubApp::class, 'source_id' => $this->githubApp->id]);
    $items = collect($this->checklist->start($this->server));

    expect($this->checklist->linkOf($items->firstWhere('key', "github_app_webhook:{$this->githubApp->uuid}")))->toBe(['https://github.com/organizations/acme/settings/apps/deploy-bot', 'Open on GitHub'])
        ->and($this->checklist->linkOf($items->firstWhere('key', "github_app_urls:{$this->githubApp->uuid}")))->toBe(['https://github.com/organizations/acme/settings/apps/deploy-bot', 'Open on GitHub']);

    Livewire::test(TransferFinish::class, ['server' => $this->server])
        ->assertSeeHtml('href="https://github.com/organizations/acme/settings/apps/deploy-bot" target="_blank"')
        ->assertSee('Open on GitHub');
});

test('a step key is passed to Livewire as a JavaScript string, so a quote in a domain cannot run script', function () {
    // parse_url keeps quotes in a host, so a domain step can have one in its key.
    $host = (string) parse_url("https://x'-alert(1)-'.example.test", PHP_URL_HOST);
    $items = $this->checklist->start($this->server);
    $metadata = $this->server->fresh()->server_metadata;
    $metadata['transfer']['finish']['items'][] = [...$items[0], 'key' => "domain:{$host}", 'type' => 'domain', 'subject' => $host, 'label' => "Domain {$host}"];
    $this->server->forceFill(['server_metadata' => $metadata])->save();

    $html = Livewire::test(TransferFinish::class, ['server' => $this->server->fresh()])->html();

    expect($html)->toContain("checkStep('domain:x\\u0027-alert(1)-\\u0027.example.test')")
        ->and($html)->not->toContain("Step('domain:x&#039;");
});

test('the SSH key of the previous instance is a manual step', function () {
    $item = collect($this->checklist->start($this->server))->firstWhere('key', 'previous_ssh_key:server');

    expect($item)->not->toBeNull()
        ->and($this->checklist->canCheck($item))->toBeFalse()
        ->and($this->checklist->linkOf($item))->toBe(["/server/{$this->server->uuid}/private-key", 'Open private key']);

    $this->checklist->markDone($this->server, 'previous_ssh_key:server');
    expect(finishTestItem($this->server, 'previous_ssh_key:server')['status'])->toBe('done');
});

test('a GitHub App link that is not a web URL, or a saved link to another host, is not shown', function () {
    finishTestApplication($this, ['source_type' => GithubApp::class, 'source_id' => $this->githubApp->id]);
    $items = collect($this->checklist->start($this->server));
    $this->githubApp->forceFill(['html_url' => 'javascript:alert(1)//'])->save();

    expect($this->checklist->linkOf($items->firstWhere('key', "github_app_webhook:{$this->githubApp->uuid}")))->toBeNull()
        ->and($this->checklist->linkOf(['type' => 'git_source', 'link' => '//evil.example/x']))->toBeNull()
        ->and($this->checklist->linkOf(['type' => 'git_source', 'link' => 'javascript:alert(1)']))->toBeNull();
});

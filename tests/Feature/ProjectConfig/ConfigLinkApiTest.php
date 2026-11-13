<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\User;
use App\Services\ProjectConfig\HeldDeployments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    app()->instance(HeldDeployments::class, new class extends HeldDeployments
    {
        public function deploy(Application $application, ?string $commit): void {}
    });
    $this->fx = projectConfigFixture();
    $this->token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
});

function linkApi(string $method, string $uri, array $body = [], ?string $token = null)
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.($token ?? test()->token), 'Accept' => 'application/json'])
        ->json($method, "/api/v1{$uri}", $body);
}

function linkApiFile(array $resources): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main'], 'staging' => ['branch' => 'staging']]]);
}

function linkApiApp(): array
{
    return [
        'type' => 'application', 'server' => 'prod-1',
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
        'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
    ];
}

function linkApiCreate(string $environment = 'production'): string
{
    return linkApi('POST', '/config/links', ['project' => 'acme', 'environment' => $environment, 'mode' => 'api'])->assertCreated()->json('uuid');
}

it('creates an api link and creates a missing project and environment', function () {
    $uuid = linkApi('POST', '/config/links', ['project' => 'shop', 'environment' => 'staging', 'mode' => 'api'])->assertCreated()->json('uuid');

    $link = EnvironmentConfigLink::where('uuid', $uuid)->first();
    expect($link->environment->name)->toBe('staging')
        ->and($link->environment->project->name)->toBe('shop')
        ->and($link->environment->project->team_id)->toBe($this->fx['team']->id)
        ->and($link->created_by)->toBe($this->fx['user']->id);
});

it('allows one link per environment', function () {
    linkApiCreate();

    linkApi('POST', '/config/links', ['project' => 'acme', 'environment' => 'production', 'mode' => 'api'])->assertStatus(409);
});

it('does not let a member link an environment', function () {
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);
    $token = $member->createToken('m', ['*'], teamId: $this->fx['team']->id)->plainTextToken;

    linkApi('POST', '/config/links', ['project' => 'acme', 'environment' => 'production', 'mode' => 'api'], $token)->assertForbidden();
});

it('applies a file in api mode', function () {
    $uuid = linkApiCreate();

    $response = linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp()])])->assertAccepted();

    expect($response->json('status'))->toBe('applied')
        ->and(Application::where('config_key', 'api')->count())->toBe(1);
});

it('returns the parse errors of an invalid file', function () {
    $uuid = linkApiCreate();

    $response = linkApi('POST', "/config/links/{$uuid}/apply", ['file' => '{"version": 1}'])->assertStatus(422);

    expect($response->json('status'))->toBe('failed')
        ->and(Application::count())->toBe(0);
});

it('rejects a file for a link that follows a branch', function () {
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'branch' => 'main', 'repository' => 'acme/api']);

    linkApi('POST', "/config/links/{$link->uuid}/apply", ['file' => linkApiFile([])])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'This environment follows its branch. Push the file instead.']);
});

it('approves a destructive plan only with the delete ability and checks delete_data keys', function () {
    $uuid = linkApiCreate();
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp(), 'worker' => linkApiApp()])])->assertAccepted();
    $revision = linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp()])])->assertOk()->json();
    expect($revision['status'])->toBe('waiting_approval');

    $writeToken = $this->fx['user']->createToken('w', ['write', 'deploy'], teamId: $this->fx['team']->id)->plainTextToken;
    linkApi('POST', "/config/revisions/{$revision['uuid']}/approve", [], $writeToken)->assertForbidden();
    linkApi('POST', "/config/revisions/{$revision['uuid']}/approve", ['delete_data' => ['api']])->assertStatus(422);

    linkApi('POST', "/config/revisions/{$revision['uuid']}/approve", ['delete_data' => ['worker']])->assertOk();

    expect(EnvironmentConfigRevision::where('uuid', $revision['uuid'])->first()->status)->toBe(ConfigRevisionStatus::Applied);
    Queue::assertPushed(DeleteResourceJob::class, 1);
});

it('lets only a team admin suspend and resume, and resume shows a plan first', function () {
    $uuid = linkApiCreate();
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp()])])->assertAccepted();
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);
    $memberToken = $member->createToken('m', ['*'], teamId: $this->fx['team']->id)->plainTextToken;

    linkApi('POST', "/config/links/{$uuid}/suspend", [], $memberToken)->assertForbidden();
    linkApi('POST', "/config/links/{$uuid}/suspend")->assertOk();
    Application::where('config_key', 'api')->first()->update(['ports_exposes' => '9000']);
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp()])])->assertStatus(409);

    $preview = linkApi('POST', "/config/links/{$uuid}/resume")->assertOk()->json();
    expect(collect($preview['changes'])->pluck('path')->all())->toBe(['ports.exposes'])
        ->and(EnvironmentConfigLink::where('uuid', $uuid)->first()->isSuspended())->toBeTrue();

    linkApi('POST', "/config/links/{$uuid}/resume?confirm=1")->assertOk();
    expect(EnvironmentConfigLink::where('uuid', $uuid)->first()->isSuspended())->toBeFalse()
        ->and(Application::where('config_key', 'api')->first()->ports_exposes)->toBe('3000');
});

it('releases a stuck revision and retries a failed one', function () {
    $uuid = linkApiCreate();
    $link = EnvironmentConfigLink::where('uuid', $uuid)->first();
    $stuck = EnvironmentConfigRevision::factory()->create(['link_id' => $link->id, 'status' => ConfigRevisionStatus::Applying]);
    $failed = EnvironmentConfigRevision::factory()->create(['link_id' => $link->id, 'status' => ConfigRevisionStatus::Failed, 'file_contents' => linkApiFile(['api' => linkApiApp()])]);

    linkApi('POST', "/config/revisions/{$stuck->uuid}/release")->assertOk();
    expect($stuck->fresh()->status)->toBe(ConfigRevisionStatus::Failed);

    linkApi('POST', "/config/revisions/{$failed->uuid}/retry")->assertAccepted();
    expect(Application::where('config_key', 'api')->count())->toBe(1);
});

it('lists revisions without the file', function () {
    $uuid = linkApiCreate();
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp()])]);

    $list = linkApi('GET', "/config/links/{$uuid}/revisions")->assertOk()->json();
    expect($list)->toHaveCount(1)
        ->and($list[0])->not->toHaveKey('file_contents')
        ->and(linkApi('GET', "/config/links/{$uuid}")->assertOk()->json('mode'))->toBe('api');
});

it('hides links of another team and checks token abilities', function () {
    $other = projectConfigFixture();
    $foreign = EnvironmentConfigLink::factory()->create(['environment_id' => $other['environment']->id]);
    $readToken = $this->fx['user']->createToken('r', ['read'], teamId: $this->fx['team']->id)->plainTextToken;
    $uuid = linkApiCreate();

    linkApi('GET', "/config/links/{$foreign->uuid}")->assertNotFound();
    linkApi('POST', "/config/links/{$foreign->uuid}/apply", ['file' => '{}'])->assertNotFound();
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => '{}'], $readToken)->assertForbidden();
    linkApi('POST', '/config/links', ['project' => 'acme', 'environment' => 'x', 'mode' => 'api'], $readToken)->assertForbidden();
});

it('lists revision plans without values for a token without read:sensitive', function () {
    $uuid = linkApiCreate();
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => linkApiApp()])]);
    $app = linkApiApp();
    $app['build']['install_command'] = 'npm ci && echo marker9731';
    linkApi('POST', "/config/links/{$uuid}/apply", ['file' => linkApiFile(['api' => $app])]);
    $readToken = $this->fx['user']->createToken('r', ['read'], teamId: $this->fx['team']->id)->plainTextToken;

    expect(linkApi('GET', "/config/links/{$uuid}/revisions", [], $readToken)->assertOk()->getContent())->not->toContain('marker9731');
    $changes = collect(linkApi('GET', "/config/links/{$uuid}/revisions", [], $readToken)->assertOk()->json('0.plan.changes'));
    expect($changes)->not->toBeEmpty()
        ->and($changes->pluck('to')->filter()->all())->toBe([])
        ->and(collect(linkApi('GET', "/config/links/{$uuid}/revisions")->json('0.plan.changes'))->pluck('to')->filter())->not->toBeEmpty();
});

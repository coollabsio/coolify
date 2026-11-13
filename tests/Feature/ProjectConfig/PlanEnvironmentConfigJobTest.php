<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\DeleteResourceJob;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Services\ProjectConfig\HeldDeployments;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'repository' => 'acme/api',
        'branch' => 'main', 'file_path' => 'coolify.json', 'read_server_id' => $this->fx['server']->id,
    ])->fresh();
    $this->files = [];
    $this->deployed = [];
    $files = &$this->files;
    $deployed = &$this->deployed;
    app()->instance(RepositoryFileReader::class, new class($files) extends RepositoryFileReader
    {
        public function __construct(private array &$files) {}

        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            $contents = $this->files[$commit ?? 'head'] ?? null;

            return new RepositoryFile($contents, $commit ?? str_repeat('a', 40), $contents === null);
        }
    });
    app()->instance(HeldDeployments::class, new class($deployed) extends HeldDeployments
    {
        public function __construct(private array &$log) {}

        public function deploy(Application $application, ?string $commit): void
        {
            $this->log[] = [$application->config_key ?? $application->name, $commit];
        }
    });
});

function planJobFile(array $resources): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);
}

function planJobApp(array $overrides = []): array
{
    return array_replace([
        'type' => 'application', 'server' => 'prod-1',
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
        'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
    ], $overrides);
}

function planJobPush(object $test, string $sha, string $file, array $held = []): EnvironmentConfigRevision
{
    $test->files[$sha] = $file;
    $test->link->update(['pending_commit_sha' => $sha]);
    PlanEnvironmentConfigJob::dispatchSync($test->link->fresh(), 'push', $sha, $held);

    return EnvironmentConfigRevision::where('commit_sha', $sha)->latest('id')->first();
}

it('auto-applies a safe push', function () {
    $revision = planJobPush($this, str_repeat('1', 40), planJobFile(['api' => planJobApp()]));

    expect($revision->status)->toBe(ConfigRevisionStatus::Applied)
        ->and(Application::where('config_key', 'api')->count())->toBe(1);
});

it('waits for approval on a destructive push, and when auto-apply is off', function () {
    planJobPush($this, str_repeat('1', 40), planJobFile(['api' => planJobApp(), 'worker' => planJobApp()]));
    $destructive = planJobPush($this, str_repeat('2', 40), planJobFile(['api' => planJobApp()]));
    expect($destructive->status)->toBe(ConfigRevisionStatus::WaitingApproval);

    $this->link->update(['auto_apply' => false]);
    $manual = planJobPush($this, str_repeat('3', 40), planJobFile(['api' => planJobApp(), 'worker' => planJobApp(['ports' => ['exposes' => '8080']])]));
    expect($manual->status)->toBe(ConfigRevisionStatus::WaitingApproval)
        ->and($destructive->fresh()->status)->toBe(ConfigRevisionStatus::Superseded);
});

it('fails on a missing file and removes nothing', function () {
    planJobPush($this, str_repeat('1', 40), planJobFile(['api' => planJobApp()]));
    $this->link->update(['pending_commit_sha' => str_repeat('2', 40)]);
    PlanEnvironmentConfigJob::dispatchSync($this->link->fresh(), 'push', str_repeat('2', 40), []);
    $revision = EnvironmentConfigRevision::where('commit_sha', str_repeat('2', 40))->first();

    expect($revision->status)->toBe(ConfigRevisionStatus::Failed)
        ->and($revision->error)->toBe('coolify.json was not found at coolify.json on main.')
        ->and(Application::where('config_key', 'api')->count())->toBe(1);
    Queue::assertNotPushed(DeleteResourceJob::class);
});

it('supersedes a waiting plan with an invalid push and fails the held deploys', function () {
    $held = Application::factory()->create([
        'name' => 'web', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]);
    planJobPush($this, str_repeat('1', 40), planJobFile(['api' => planJobApp(), 'worker' => planJobApp()]));
    $waiting = planJobPush($this, str_repeat('2', 40), planJobFile(['api' => planJobApp()]), [$held->uuid]);
    expect($waiting->status)->toBe(ConfigRevisionStatus::WaitingApproval);

    $invalid = planJobPush($this, str_repeat('3', 40), '{"version": 1', [$held->uuid]);

    expect($waiting->fresh()->status)->toBe(ConfigRevisionStatus::Superseded)
        ->and($invalid->status)->toBe(ConfigRevisionStatus::Failed)
        ->and(ApplicationDeploymentQueue::where('application_id', $held->id)->where('status', 'failed')->count())->toBe(1)
        ->and(collect($this->deployed)->where(0, 'web')->all())->toBe([]);
});

it('only plans while the link is suspended', function () {
    $this->link->update(['suspended_at' => now()]);
    $revision = planJobPush($this, str_repeat('1', 40), planJobFile(['api' => planJobApp()]));

    expect($revision->status)->toBe(ConfigRevisionStatus::Planned)
        ->and(Application::count())->toBe(0);
});

it('skips a push that is no longer the newest', function () {
    $this->files[str_repeat('1', 40)] = planJobFile(['api' => planJobApp()]);
    $this->link->update(['pending_commit_sha' => str_repeat('2', 40)]);

    PlanEnvironmentConfigJob::dispatchSync($this->link->fresh(), 'push', str_repeat('1', 40), []);

    expect(EnvironmentConfigRevision::first()->status)->toBe(ConfigRevisionStatus::Skipped)
        ->and(Application::count())->toBe(0);
});

it('releases the held deploys with their commit after an apply', function () {
    $held = Application::factory()->create([
        'name' => 'web', 'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(),
    ]);
    planJobPush($this, str_repeat('1', 40), planJobFile(['api' => planJobApp()]), [$held->uuid]);

    expect($this->deployed)->toContain(['web', str_repeat('1', 40)]);
});

it('creates a git link with team sources only, and plans the branch head', function () {
    $token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
    $other = projectConfigFixture();
    session(['currentTeam' => $this->fx['team']]);
    $foreignApp = GithubApp::forceCreate([
        'uuid' => 'gh-foreign', 'name' => 'foreign', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => $other['team']->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $other['team']->id])->id,
    ]);
    $this->files['head'] = planJobFile(['api' => planJobApp()]);
    $staging = $this->fx['project']->environments()->create(['name' => 'staging']);
    $body = fn (array $extra) => array_merge(['project' => 'acme', 'environment' => 'staging', 'mode' => 'git', 'repository' => 'https://github.com/acme/api', 'branch' => 'main', 'read_server_uuid' => $this->fx['server']->uuid, 'source_type' => 'public'], $extra);
    $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

    app('auth')->forgetGuards();
    $this->withHeaders($headers)->postJson('/api/v1/config/links', $body(['source_type' => 'github_app', 'source_uuid' => $foreignApp->uuid]))->assertStatus(422);
    app('auth')->forgetGuards();
    $this->withHeaders($headers)->postJson('/api/v1/config/links', $body(['read_server_uuid' => $other['server']->uuid]))->assertStatus(422);
    foreach ([['repository' => 'file:///srv/git/api.git'], ['repository' => 'http://127.0.0.1/api.git'], ['file_path' => '../coolify.json']] as $invalid) {
        app('auth')->forgetGuards();
        $this->withHeaders($headers)->postJson('/api/v1/config/links', $body($invalid))->assertStatus(422);
    }
    app('auth')->forgetGuards();
    $created = $this->withHeaders($headers)->postJson('/api/v1/config/links', $body([]))->assertCreated()->json();

    $link = EnvironmentConfigLink::where('uuid', $created['uuid'])->first();
    expect($link->environment_id)->toBe($staging->id)
        ->and($link->webhook_secret)->toHaveLength(40)
        ->and($link->revisions()->count())->toBe(1);
});

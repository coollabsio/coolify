<?php

use App\Enums\ConfigRevisionStatus;
use App\Jobs\ApplyEnvironmentConfigJob;
use App\Jobs\CommentConfigPlanJob;
use App\Jobs\DeleteResourceJob;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\S3Storage;
use App\Models\Service;
use App\Models\User;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\ConfigPlanner;
use App\Services\ProjectConfig\ConfigPushRouter;
use App\Services\ProjectConfig\ConfigResolver;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\HeldDeployments;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    $this->fx = projectConfigFixture();
    $this->link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id])->fresh();
    $this->deployed = [];
    $this->restarted = [];
    $deployed = &$this->deployed;
    $restarted = &$this->restarted;
    app()->instance(HeldDeployments::class, new class($deployed, $restarted) extends HeldDeployments
    {
        public function __construct(private array &$deploys, private array &$restarts) {}

        public function deploy(Application $application, ?string $commit): void
        {
            $this->deploys[] = $application->config_key;
        }

        public function restart(Model $resource, bool $created): void
        {
            $this->restarts[] = $resource->config_key;
        }
    });
});

function fixesFile(array $resources): string
{
    return json_encode(['version' => 1, 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]]);
}

function fixesApp(array $overrides = []): array
{
    return array_replace([
        'type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
    ], $overrides);
}

function fixesApply(array $resources): EnvironmentConfigRevision
{
    $revision = app(ConfigRevisions::class)->record(test()->link->fresh(), fixesFile($resources), 'api');
    app(ConfigApplier::class)->apply($revision);

    return $revision->fresh();
}

const FIXES_COMPOSE = "services:\n  web:\n    image: 'nginx:alpine'\n    environment:\n      - SERVICE_FQDN_WEB_80\n      - 'MODE=\${MODE:-prod}'\n";

it('lets Coolify parse and save variables of a managed service outside the apply', function () {
    fixesApply(['stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => FIXES_COMPOSE, 'services' => ['web' => ['domains' => ['https://stack.acme.com']]]]]);
    $service = Service::where('config_key', 'stack')->first();

    $service->parse();
    EnvironmentVariable::where('resourceable_id', $service->id)->where('resourceable_type', $service->getMorphClass())->get()->each->save();

    expect($service->environment_variables()->where('key', 'like', 'SERVICE_%')->exists())->toBeTrue();
});

it('still blocks a user change of a managed variable', function () {
    fixesApply(['api' => fixesApp(['env' => ['NODE_ENV' => ['value' => 'production']]])]);
    $variable = EnvironmentVariable::where('key', 'NODE_ENV')->where('is_preview', false)->first();

    expect(fn () => $variable->update(['value' => 'dev']))->toThrow(ValidationException::class);
});

it('checks server placement and team credentials for the actor in every resource type', function () {
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);
    S3Storage::forceCreate(['name' => 'backups', 'team_id' => $this->fx['team']->id, 'region' => 'x', 'key' => 'k', 'secret' => 's', 'bucket' => 'b', 'endpoint' => 'https://s3.example.com']);
    $file = fixesFile([
        'db' => ['type' => 'postgresql', 'server' => 'prod-1', 'backups' => [['frequency' => 'daily', 's3_storage' => 'backups']]],
        'stack' => ['type' => 'service', 'server' => 'prod-1', 'compose' => FIXES_COMPOSE],
    ]);
    $resolved = app(ConfigResolver::class)->forBranch(app(ConfigParser::class)->parse($file), 'main');

    $paths = array_map(fn ($e) => $e->path, app(ConfigPlanner::class)->plan($resolved, ApplyContext::for($this->link, $member))->errors);

    expect($paths)->toContain('resources.db.server', 'resources.stack.server', 'resources.db.backups.0.s3_storage');
});

it('shows the webhook secret and URL when a git link is created', function () {
    $token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
    $staging = $this->fx['project']->environments()->create(['name' => 'staging']);
    Queue::fake([PlanEnvironmentConfigJob::class]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->postJson('/api/v1/config/links', [
        'project' => 'acme', 'environment' => 'staging', 'mode' => 'git', 'repository' => 'https://github.com/acme/api', 'branch' => 'main',
        'source_type' => 'public', 'read_server_uuid' => $this->fx['server']->uuid,
    ])->assertCreated();

    expect($response->json('webhook_secret'))->toHaveLength(40)
        ->and($response->json('webhook_urls.github'))->toEndWith('/webhooks/source/github/events/manual');
});

it('does not hold deploys or apply while the link is suspended', function () {
    fixesApply(['api' => fixesApp()]);
    $app = Application::where('config_key', 'api')->first();
    $this->link->update(['mode' => 'git', 'branch' => 'main', 'repository' => 'acme/api', 'suspended_at' => now()]);

    $router = app(ConfigPushRouter::class);
    $router->beginPush(null, fn () => true, 'main', str_repeat('a', 40), fn () => true);
    expect($router->hold($app))->toBeFalse();

    $revision = EnvironmentConfigRevision::factory()->create(['link_id' => $this->link->id, 'status' => ConfigRevisionStatus::WaitingApproval, 'approved_at' => now(), 'file_contents' => fixesFile(['api' => fixesApp(['ports' => ['exposes' => '9000']])])]);
    app(ConfigApplier::class)->apply($revision);

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::WaitingApproval)
        ->and($app->fresh()->ports_exposes)->toBe('3000');
});

it('recovers held deploys when the webhook flush did not run', function () {
    Queue::fake([PlanEnvironmentConfigJob::class]);
    fixesApply(['api' => fixesApp()]);
    $app = Application::where('config_key', 'api')->first();
    $this->link->update(['mode' => 'git', 'branch' => 'main', 'repository' => 'acme/api', 'webhook_secret' => 's']);

    $router = new ConfigPushRouter;
    $router->beginPush(null, fn () => true, 'main', str_repeat('b', 40), fn () => true);
    $router->hold($app);
    $this->travel(3)->minutes();

    $this->artisan('project-config:release-stuck')->assertSuccessful();

    Queue::assertPushed(PlanEnvironmentConfigJob::class, fn ($job) => $job->commit === str_repeat('b', 40) && $job->heldApplicationUuids === [$app->uuid]);
});

it('does not apply two revisions of one link at the same time, and never an older one after a newer one', function () {
    Queue::fake([ApplyEnvironmentConfigJob::class]);
    fixesApply(['api' => fixesApp()]);
    $older = app(ConfigRevisions::class)->record($this->link->fresh(), fixesFile(['api' => fixesApp(['ports' => ['exposes' => '8000']])]), 'api');
    $newer = app(ConfigRevisions::class)->record($this->link->fresh(), fixesFile(['api' => fixesApp(['ports' => ['exposes' => '9000']])]), 'api');

    $lock = Cache::lock("project-config:link:{$this->link->id}", 60);
    $lock->get();
    app(ConfigApplier::class)->apply($newer);
    expect($newer->fresh()->status)->toBe(ConfigRevisionStatus::Planned);
    Queue::assertPushed(ApplyEnvironmentConfigJob::class);
    $lock->release();

    app(ConfigApplier::class)->apply($newer->fresh());
    app(ConfigApplier::class)->apply($older->fresh());

    expect(Application::where('config_key', 'api')->first()->ports_exposes)->toBe('9000')
        ->and($older->fresh()->status)->toBe(ConfigRevisionStatus::Superseded);
});

it('does not redeploy or restart for a tag-only change', function () {
    fixesApply(['api' => fixesApp(), 'db' => ['type' => 'redis', 'server' => 'prod-1']]);
    $this->deployed = [];
    $this->restarted = [];

    fixesApply(['api' => fixesApp(['tags' => ['web']]), 'db' => ['type' => 'redis', 'server' => 'prod-1', 'tags' => ['cache']]]);

    expect($this->deployed)->toBe([])->and($this->restarted)->toBe([]);
});

it('does not wait for approval of an empty plan when auto-apply is off', function () {
    fixesApply(['api' => fixesApp()]);
    $this->link->update(['auto_apply' => false]);

    $revision = app(ConfigRevisions::class)->record($this->link->fresh(), fixesFile(['api' => fixesApp()]), 'push');

    expect($revision->status)->toBe(ConfigRevisionStatus::Planned);
});

it('keeps error messages out of pull request comments', function () {
    $github = GithubApp::create(['name' => 'gh', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com', 'is_public' => true, 'team_id' => $this->fx['team']->id]);
    Application::factory()->create(['name' => 'secret-internal-app', 'fqdn' => 'https://taken.acme.com', 'environment_id' => $this->fx['environment']->id, 'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass()]);
    $this->link->update(['mode' => 'git', 'branch' => 'main', 'repository' => 'acme/api', 'source_type' => GithubApp::class, 'source_id' => $github->id, 'read_server_id' => $this->fx['server']->id]);
    app()->instance(RepositoryFileReader::class, new class extends RepositoryFileReader
    {
        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile(fixesFile(['api' => fixesApp(['domains' => ['https://taken.acme.com']])]), (string) $commit, false);
        }
    });
    Http::fake(['*' => Http::response([], 200)]);

    CommentConfigPlanJob::dispatchSync($this->link->fresh(), 3, str_repeat('c', 40));

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request['body'], 'resources.api.domains')
        && ! str_contains($request['body'], 'secret-internal-app'));
});

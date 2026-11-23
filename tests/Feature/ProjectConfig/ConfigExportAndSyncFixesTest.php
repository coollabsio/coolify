<?php

use App\Actions\Application\StopApplicationOneServer;
use App\Enums\ConfigRevisionStatus;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Children\AdditionalServers;
use App\Services\ProjectConfig\ConfigExporter;
use App\Services\ProjectConfig\ConfigParser;
use App\Services\ProjectConfig\HeldDeployments;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->fx = projectConfigFixture();
});

function exportFixApp(string $name, ?string $configKey = null): Application
{
    $application = Application::factory()->create([
        'name' => $name, 'environment_id' => test()->fx['environment']->id,
        'destination_id' => test()->fx['destination']->id, 'destination_type' => test()->fx['destination']->getMorphClass(),
    ]);
    if ($configKey !== null) {
        $application->forceFill(['config_key' => $configKey])->saveQuietly();
    }

    return $application;
}

it('exports an empty environment with resources as a JSON object', function () {
    $json = json_encode(app(ConfigExporter::class)->export($this->fx['environment']));

    expect($json)->toContain('"resources":{}');
});

it('does not give an unkeyed resource the key of a managed resource', function () {
    $unkeyed = exportFixApp('api');
    $managed = exportFixApp('backend', 'api');

    $export = app(ConfigExporter::class)->export($this->fx['environment']);

    expect(array_keys($export['resources']))->toEqualCanonicalizing(['api', 'api-2'])
        ->and($export['environments']['production']['import'])->toBe([['to' => 'api-2', 'uuid' => $unkeyed->uuid]]);
});

it('proposes keys of at most 63 characters, also with a prefix and a suffix', function () {
    exportFixApp('1'.str_repeat('a', 70));
    exportFixApp('1'.str_repeat('a', 70));

    $keys = array_keys(app(ConfigExporter::class)->export($this->fx['environment'])['resources']);

    expect($keys)->toHaveCount(2);
    foreach ($keys as $key) {
        expect(preg_match(ConfigParser::KEY_PATTERN, $key))->toBe(1);
    }
});

it('keeps two additional destinations on one server, reads them in canonical order, and stops removed servers', function () {
    Queue::fake();
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $first = StandaloneDocker::where('server_id', $other->id)->first();
    $first->update(['network' => 'net-a']);
    StandaloneDocker::forceCreate(['name' => 'b', 'network' => 'net-b', 'server_id' => $other->id, 'uuid' => new_public_id()]);
    $application = exportFixApp('api');
    $link = EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);
    $ctx = ApplyContext::for($link->fresh());
    $children = new AdditionalServers;
    $desired = [['server' => 'prod-2', 'destination' => 'net-b'], ['server' => 'prod-2', 'destination' => 'net-a']];

    DB::transaction(fn () => $children->write($application, $desired, $ctx));

    expect($application->additional_networks()->count())->toBe(2)
        ->and($children->read($application->fresh()))->toBe($children->canonical($desired))
        ->and($children->read($application->fresh()))->toBe([['destination' => 'net-a', 'server' => 'prod-2'], ['destination' => 'net-b', 'server' => 'prod-2']]);
    StopApplicationOneServer::assertNotPushed();

    DB::transaction(fn () => $children->write($application->fresh(), [], $ctx));

    expect($application->additional_networks()->count())->toBe(0);
    StopApplicationOneServer::assertPushed(fn ($action, $args) => $args[1]->is($other));
});

it('skips an older push when a newer push came in while the file was read', function () {
    $link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'mode' => 'git', 'repository' => 'acme/api',
        'branch' => 'main', 'file_path' => 'coolify.json', 'read_server_id' => $this->fx['server']->id,
    ]);
    $older = str_repeat('1', 40);
    $newer = str_repeat('2', 40);
    $link->update(['pending_commit_sha' => $older]);
    app()->instance(RepositoryFileReader::class, new class($newer) extends RepositoryFileReader
    {
        public function __construct(private string $newer) {}

        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            // A newer push arrives while this read runs.
            $link->update(['pending_commit_sha' => $this->newer]);

            return new RepositoryFile(json_encode(['version' => 1, 'resources' => [], 'environments' => ['production' => ['branch' => 'main']]]), (string) $commit, false);
        }
    });

    PlanEnvironmentConfigJob::dispatchSync($link->fresh(), 'push', $older, []);

    expect(EnvironmentConfigRevision::where('commit_sha', $older)->pluck('status')->all())->toBe([ConfigRevisionStatus::Skipped]);
});

it('records a failed deployment when the queue is full after an apply', function () {
    $application = exportFixApp('api');
    $this->fx['server']->settings->forceFill(['deployment_queue_limit' => 0])->save();

    app(HeldDeployments::class)->deploy($application, null);

    $deployment = ApplicationDeploymentQueue::where('application_id', $application->id)->sole();
    expect($deployment->status)->toBe('failed')
        ->and(json_encode($deployment->logs))->toContain('was not queued');
});

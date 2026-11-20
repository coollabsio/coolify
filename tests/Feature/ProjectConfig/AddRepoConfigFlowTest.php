<?php

use App\Data\RepositoryDetectionResult;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\User;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use App\Services\RepositoryDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([PlanEnvironmentConfigJob::class]);
    $this->fx = projectConfigFixture();
    app()->instance(RepositoryFileReader::class, new class extends RepositoryFileReader
    {
        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            return new RepositoryFile(json_encode(['version' => 1, 'resources' => ['web' => [
                'type' => 'application', 'server' => 'prod-1', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
                'source' => ['repository' => 'https://github.com/acme/web', 'branch' => 'main'],
            ]], 'environments' => ['production' => ['branch' => 'main']]]), str_repeat('a', 40), false);
        }
    });
});

class AddRepoFormWithConfigFile extends PublicGitRepository
{
    public function fakeScan(): void
    {
        $this->applyDetectionResult(new RepositoryDetectionResult(configFile: 'coolify.json'));
    }

    public function render()
    {
        return view('livewire.project.new.public-git-repository');
    }
}

function addRepoForm(object $test, User $user, string $component = PublicGitRepository::class)
{
    $test->actingAs($user);

    return Livewire::test($component, ['type' => 'public'])
        ->set('parameters', ['project_uuid' => $test->fx['project']->uuid, 'environment_uuid' => $test->fx['environment']->uuid])
        ->set('query', ['destination' => $test->fx['destination']->uuid])
        ->set('git_repository', 'https://github.com/acme/web')
        ->set('git_branch', 'main');
}

it('finds coolify.json in the scan output', function () {
    $detector = new ReflectionClass(RepositoryDetector::class);
    $parse = $detector->getMethod('parseOutput');
    $instance = $detector->newInstanceWithoutConstructor();

    $result = $parse->invoke($instance, json_encode(['dockerfiles' => ['Dockerfile'], 'dockerComposeFiles' => [], 'envFiles' => [], 'dockerfilePorts' => [], 'configFile' => 'coolify.json']));

    expect($result->configFile)->toBe('coolify.json');
});

it('shows the plan of the file before linking', function () {
    addRepoForm($this, $this->fx['user'])
        ->call('previewConfigFile')
        ->assertSet('configPlan.changes.0.key', 'web')
        ->assertSet('configPlan.link.branch', 'main')
        ->assertSet('configPlan.link.repository', 'https://github.com/acme/web');

    expect(EnvironmentConfigLink::count())->toBe(0);
});

it('links the environment when an admin confirms', function () {
    addRepoForm($this, $this->fx['user'])
        ->call('previewConfigFile')
        ->call('useConfigFile')
        ->assertRedirect(route('project.environment.configuration', ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid]));

    $link = EnvironmentConfigLink::first();
    expect($link->environment_id)->toBe($this->fx['environment']->id)
        ->and($link->mode)->toBe('git')
        ->and($link->repository)->toBe('https://github.com/acme/web')
        ->and($link->branch)->toBe('main')
        ->and($link->read_server_id)->toBe($this->fx['server']->id);
    Queue::assertPushed(PlanEnvironmentConfigJob::class);
});

it('does not let a member link the environment', function () {
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);

    addRepoForm($this, $member)->call('useConfigFile');

    expect(EnvironmentConfigLink::count())->toBe(0);
});

it('shows the plan instead of creating an application when the form uses coolify.json', function () {
    addRepoForm($this, $this->fx['user'], AddRepoFormWithConfigFile::class)
        ->set('branchFound', true)
        ->call('fakeScan')
        ->assertSet('configSource', 'file')
        ->call('submit')
        ->assertSet('configPlan.changes.0.key', 'web')
        ->assertSee('Create resources')
        ->assertDontSee('Build pack');

    expect(Application::count())->toBe(0);
});

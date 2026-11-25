<?php

use App\Data\RepositoryDetectionResult;
use App\Livewire\Project\New\PublicGitRepository;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentVariable;
use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Services\ProjectConfig\RepositoryFile;
use App\Services\ProjectConfig\RepositoryFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * A template file without a repository: the application builds the repository that the user adds.
 */
function templateFile(array $resources): void
{
    app()->instance(RepositoryFileReader::class, new class($resources) extends RepositoryFileReader
    {
        public ?string $readCommit = null;

        public int $reads = 0;

        public function __construct(private array $resources) {}

        public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
        {
            $this->readCommit = $commit;
            $this->reads++;

            return new RepositoryFile(json_encode(['version' => 1, 'resources' => $this->resources]), str_repeat('b', 40), false);
        }
    });
}

class TemplateRepoForm extends PublicGitRepository
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

function templateForm(object $test, User $user)
{
    $test->actingAs($user);

    return Livewire::test(TemplateRepoForm::class, ['type' => 'public'])
        ->set('parameters', ['project_uuid' => $test->fx['project']->uuid, 'environment_uuid' => $test->fx['environment']->uuid])
        ->set('query', ['destination' => $test->fx['destination']->uuid])
        ->set('git_repository', 'https://github.com/someone/fork')
        ->set('git_branch', 'main')
        ->set('branchFound', true)
        ->call('fakeScan');
}

beforeEach(function () {
    Queue::fake();
    $this->fx = projectConfigFixture();
    templateFile([
        'web' => [
            'type' => 'application',
            'build' => ['pack' => 'dockerfile'],
            'ports' => ['exposes' => '3000'],
            'env' => ['API_KEY' => ['required' => true], 'MODE' => ['value' => 'production']],
        ],
    ]);
});

it('creates normal resources from coolify.json with the repository of the form', function () {
    $component = templateForm($this, $this->fx['user'])
        ->assertSet('configSource', 'template')
        ->call('submit')
        ->assertSet('configPlan.changes.0.key', 'web')
        ->call('useConfigTemplate');

    $application = Application::sole();
    $component->assertRedirect(route('project.application.configuration', [
        'project_uuid' => $this->fx['project']->uuid,
        'environment_uuid' => $this->fx['environment']->uuid,
        'application_uuid' => $application->uuid,
    ]));
    expect(EnvironmentConfigLink::count())->toBe(0)
        ->and($application->config_key)->toBeNull()
        ->and($application->git_repository)->toBe('https://github.com/someone/fork')
        ->and($application->git_branch)->toBe('main')
        ->and($application->ports_exposes)->toBe('3000')
        ->and($application->destination_id)->toBe($this->fx['destination']->id)
        ->and(app(RepositoryFileReader::class)->readCommit)->toBe(str_repeat('b', 40));

    $variables = EnvironmentVariable::query()->where('resourceable_id', $application->id)->where('is_preview', false)->get()->keyBy('key');
    expect((bool) $variables['API_KEY']->is_required)->toBeTrue()
        ->and($variables['API_KEY']->config_meta)->toBeNull()
        ->and($variables['MODE']->value)->toBe('production')
        ->and($variables['MODE']->config_meta)->toBeNull();

    // The resource is not managed: a member with access edits it like any other resource.
    expect($this->fx['user']->can('update', $application))->toBeTrue();
});

it('opens the environment when the template creates more than one resource', function () {
    templateFile([
        'web' => ['type' => 'application', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000']],
        'db' => ['type' => 'postgresql'],
    ]);

    templateForm($this, $this->fx['user'])
        ->call('submit')
        ->call('useConfigTemplate')
        ->assertRedirect(route('project.resource.index', ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid]));

    expect(Application::sole()->config_key)->toBeNull()
        ->and(StandalonePostgresql::sole()->config_key)->toBeNull();
});

it('does not let a read-only member create resources from the template', function () {
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(PublicGitRepository::class, ['type' => 'public'])
        ->set('parameters', ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid])
        ->set('query', ['destination' => $this->fx['destination']->uuid])
        ->set('git_repository', 'https://github.com/someone/fork')
        ->set('git_branch', 'main')
        ->call('useConfigTemplate');

    expect(Application::count())->toBe(0);
});

it('does not create resources from a template in a linked environment', function () {
    EnvironmentConfigLink::factory()->create(['environment_id' => $this->fx['environment']->id]);

    templateForm($this, $this->fx['user'])
        ->call('submit')
        ->assertSet('configPlanError', 'This environment follows coolify.json. Add the resources to its file instead.')
        ->call('useConfigTemplate');

    expect(Application::count())->toBe(0);
});

it('stops when a resource of an earlier link uses a key of the file', function () {
    Application::factory()->create(['environment_id' => $this->fx['environment']->id, 'destination_id' => $this->fx['destination']->id, 'destination_type' => $this->fx['destination']->getMorphClass(), 'config_key' => 'web']);

    templateForm($this, $this->fx['user'])
        ->call('submit')
        ->assertSet('configPlan.errors.0.path', 'resources.web')
        ->call('useConfigTemplate');

    expect(Application::count())->toBe(1);
});

it('reads the file one time for each choice when the user switches between the choices', function () {
    templateForm($this, $this->fx['user'])
        ->call('submit')
        ->set('configSource', 'file')
        ->set('configSource', 'manual')
        ->set('configSource', 'template')
        ->assertSet('configPlan.changes.0.key', 'web')
        ->set('configSource', 'file');

    expect(app(RepositoryFileReader::class)->reads)->toBe(2);
});

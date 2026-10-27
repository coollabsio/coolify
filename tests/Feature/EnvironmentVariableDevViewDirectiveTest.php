<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\SharedVariables\Team\Index;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);
    EnvironmentVariable::query()->delete();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->createVariable = fn (string $key, array $attributes = []) => EnvironmentVariable::create([
        'key' => $key,
        'value' => 'value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        ...$attributes,
    ]);
    $this->flags = fn (string $key) => $this->application->environment_variables()->where('key', $key)->firstOrFail()
        ->only(['value', 'is_literal', 'is_buildtime', 'is_runtime']);
    $this->devView = fn () => Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch');
});

it('shows directives for non-default flags and keeps flags when saved without edits', function () {
    ($this->createVariable)('CUSTOM', ['is_literal' => true, 'is_buildtime' => false]);
    ($this->createVariable)('PLAIN');

    $component = ($this->devView)();

    expect($component->get('variables'))->toBe("# coolify: literal buildtime=false\nCUSTOM=value\nPLAIN=value");

    $component->call('submit')->assertNotDispatched('error');

    expect(($this->flags)('CUSTOM'))->toBe(['value' => 'value', 'is_literal' => true, 'is_buildtime' => false, 'is_runtime' => true])
        ->and(($this->flags)('PLAIN'))->toBe(['value' => 'value', 'is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true]);
});

it('sets flags from an added directive and resets them with explicit values', function () {
    ($this->createVariable)('A');
    ($this->createVariable)('B', ['is_literal' => true, 'is_runtime' => false]);

    ($this->devView)()
        ->set('variables', "# coolify: literal\nA=value\n# coolify: literal=false runtime=true\nB=value")
        ->call('submit')
        ->assertNotDispatched('error');

    expect(($this->flags)('A'))->toBe(['value' => 'value', 'is_literal' => true, 'is_buildtime' => true, 'is_runtime' => true])
        ->and(($this->flags)('B'))->toBe(['value' => 'value', 'is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true]);
});

it('keeps flags of existing variables when a plain env file without directives is saved', function () {
    ($this->createVariable)('A', ['is_literal' => true, 'is_buildtime' => false, 'is_runtime' => false]);

    ($this->devView)()
        ->set('variables', "A=changed\nNEW=new")
        ->call('submit')
        ->assertNotDispatched('error');

    expect(($this->flags)('A'))->toBe(['value' => 'changed', 'is_literal' => true, 'is_buildtime' => false, 'is_runtime' => false])
        ->and(($this->flags)('NEW'))->toBe(['value' => 'new', 'is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true]);
});

it('creates a new variable with the flags of its directive', function () {
    ($this->devView)()
        ->set('variables', "# coolify: literal buildtime=false\nNEW=new")
        ->call('submit')
        ->assertNotDispatched('error');

    expect(($this->flags)('NEW'))->toBe(['value' => 'new', 'is_literal' => true, 'is_buildtime' => false, 'is_runtime' => true]);
});

it('rejects an invalid directive, changes nothing, and keeps the text', function () {
    ($this->createVariable)('A');
    $text = "A=changed\nNEW=new\n# coolify: literal";

    $component = ($this->devView)()
        ->set('variables', $text)
        ->call('submit')
        ->assertDispatched('error', 'Line 3: a "# coolify:" line must be directly above a KEY=value line.');

    expect($component->get('variables'))->toBe($text)
        ->and($this->application->environment_variables()->pluck('value', 'key')->all())->toBe(['A' => 'value']);
});

it('ignores a directive above a locked variable', function () {
    ($this->createVariable)('LOCKED', ['is_shown_once' => true]);

    $component = ($this->devView)();
    expect($component->get('variables'))->toBe('LOCKED=(Locked Secret, delete and add again to change)');

    $component->set('variables', "# coolify: literal\nLOCKED=(Locked Secret, delete and add again to change)")
        ->call('submit')
        ->assertNotDispatched('error');

    expect(($this->flags)('LOCKED'))->toBe(['value' => 'value', 'is_literal' => false, 'is_buildtime' => true, 'is_runtime' => true]);
});

it('applies the literal directive to team shared variables and rejects buildtime', function () {
    SharedEnvironmentVariable::create(['key' => 'A', 'value' => 'value', 'type' => 'team', 'team_id' => $this->team->id]);

    $component = Livewire::test(Index::class)
        ->call('switch')
        ->set('variables', "# coolify: literal\nA=value\n# coolify: literal\nNEW=new")
        ->call('submit')
        ->assertNotDispatched('error');

    expect($this->team->environment_variables()->pluck('is_literal', 'key')->all())->toBe(['A' => true, 'NEW' => true])
        ->and($component->get('variables'))->toBe("# coolify: literal\nA=value\n# coolify: literal\nNEW=new");

    $component->set('variables', "# coolify: buildtime=false\nA=value")
        ->call('submit')
        ->assertDispatched('error', 'Line 1: unknown flag "buildtime". Allowed flags: literal.');

    expect($this->team->environment_variables()->count())->toBe(2);
});

it('names the preview text area in a directive error', function () {
    expect($this->application->git_repository)->not->toBeEmpty();

    ($this->devView)()
        ->set('variablesPreview', "A=1\n# coolify: literal")
        ->call('submit')
        ->assertDispatched('error', 'Preview deployments: Line 2: a "# coolify:" line must be directly above a KEY=value line.');
});

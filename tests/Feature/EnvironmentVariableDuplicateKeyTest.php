<?php

use App\Livewire\Project\Shared\EnvironmentVariable\All;
use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($this->user, ['role' => 'owner']);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create(['environment_id' => $environment->id]);

    $this->actingAs($this->user);
});

function duplicateKeyTestVariable(Application $application, string $key, bool $isPreview = false, string $value = 'value'): EnvironmentVariable
{
    // Without events, so that the created hook adds no preview copy.
    return EnvironmentVariable::withoutEvents(fn () => EnvironmentVariable::query()->forceCreate([
        'uuid' => new_public_id(),
        'key' => $key,
        'value' => $value,
        'is_preview' => $isPreview,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]));
}

test('a second variable with the same key on one resource is refused', function () {
    duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS');

    expect(fn () => duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS'))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the same key can exist for production and preview, and on other resources', function () {
    $other = Application::factory()->create(['environment_id' => $this->application->environment_id]);

    duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS');
    duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS', isPreview: true);
    duplicateKeyTestVariable($other, 'JOB_ATTEMPTS');

    expect(EnvironmentVariable::query()->where('key', 'JOB_ATTEMPTS')->count())->toBe(3);
});

test('a create that loses the race to another request returns the row of that request', function () {
    $existing = duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS', value: '3');

    // firstOrCreate() goes this way when its lookup ran before the other request inserted the row.
    $result = $this->application->environment_variables()->createOrFirst(['key' => 'JOB_ATTEMPTS'], ['value' => '5']);

    expect($result->id)->toBe($existing->id)
        ->and($this->application->environment_variables()->where('key', 'JOB_ATTEMPTS')->pluck('value')->all())->toBe(['3']);
});

test('renaming a variable to a key that the resource already has is refused', function () {
    duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS', value: '3');
    $renamed = duplicateKeyTestVariable($this->application, 'JOB_BACKOFF_DELAY', value: '1000');

    Livewire::test(Show::class, ['env' => $renamed, 'type' => 'application'])
        ->set('key', 'JOB_ATTEMPTS')
        ->call('submit')
        ->assertDispatched('error', 'Environment variable already exists.')
        ->assertSet('key', 'JOB_BACKOFF_DELAY');

    expect($renamed->fresh()->key)->toBe('JOB_BACKOFF_DELAY');
});

test('adding a preview variable whose key already exists for previews is refused', function () {
    duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS', isPreview: true);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('submit', ['key' => 'JOB_ATTEMPTS', 'value' => '3', 'is_preview' => true])
        ->assertDispatched('error', 'Environment variable already exists.');

    expect($this->application->environment_variables_preview()->where('key', 'JOB_ATTEMPTS')->count())->toBe(1);
});

test('saving the developer view again does not add rows', function () {
    $component = Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch')
        ->set('variables', "JOB_ATTEMPTS=3\nJOB_BACKOFF_DELAY=1000");

    $component->call('submit')->call('submit')
        ->set('variables', "JOB_ATTEMPTS=4\nJOB_BACKOFF_DELAY=1000")
        ->call('submit');

    expect($this->application->environment_variables()->whereIn('key', ['JOB_ATTEMPTS', 'JOB_BACKOFF_DELAY'])->pluck('value', 'key')->all())
        ->toBe(['JOB_ATTEMPTS' => '4', 'JOB_BACKOFF_DELAY' => '1000'])
        ->and($this->application->environment_variables()->whereIn('key', ['JOB_ATTEMPTS', 'JOB_BACKOFF_DELAY'])->count())->toBe(2);
});

test('saving the developer view gives every existing copy of a key the saved value', function () {
    duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS', value: '3');
    insertDuplicateEnvironmentVariable([
        'key' => 'JOB_ATTEMPTS',
        'value' => '5',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('loadEnvironmentVariables')
        ->call('switch')
        ->set('variables', 'JOB_ATTEMPTS=7')
        ->call('submit');

    expect($this->application->environment_variables()->where('key', 'JOB_ATTEMPTS')->pluck('value')->all())->toBe(['7', '7']);
});

test('an extra copy of a Compose variable can be deleted, but not its last row', function () {
    $this->application->update([
        'build_pack' => 'dockercompose',
        'docker_compose' => "services:\n  app:\n    image: nginx\n    environment:\n      JOB_ATTEMPTS: \${JOB_ATTEMPTS}\n",
    ]);
    $first = duplicateKeyTestVariable($this->application, 'JOB_ATTEMPTS', value: '3');
    $copy = insertDuplicateEnvironmentVariable([
        'key' => 'JOB_ATTEMPTS',
        'value' => '3',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    Livewire::test(Show::class, ['env' => $copy, 'type' => 'application'])
        ->call('delete')
        ->assertDispatched('success', 'Environment variable deleted successfully.');

    Livewire::test(Show::class, ['env' => $first->fresh(), 'type' => 'application'])
        ->call('delete')
        ->assertNotDispatched('success');

    expect($this->application->environment_variables()->where('key', 'JOB_ATTEMPTS')->pluck('id')->all())->toBe([$first->id]);
});

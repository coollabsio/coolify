<?php

use App\Livewire\SharedVariables\Environment\Show;
use App\Models\Environment;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use App\Services\Infisical\InfisicalLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], []));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'admin']);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    InfisicalConnection::factory()->create([
        'team_id' => $this->team->id,
        'is_enabled' => true,
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

afterEach(function () {
    request()->setRouteResolver(fn () => null);
});

function infisicalOwnedVariable(string $key = 'DB_PASSWORD', string $value = 'from-infisical', array $extra = []): SharedEnvironmentVariable
{
    // The team's connection is enabled in beforeEach, so the managed-variable
    // lock rejects this as a human edit. These rows stand in for values Coolify
    // pulled down from Infisical, so create them as system writes.
    return InfisicalLock::asSystem(fn () => SharedEnvironmentVariable::create(array_merge([
        'key' => $key,
        'value' => $value,
        'type' => 'environment',
        'team_id' => test()->team->id,
        'environment_id' => test()->environment->id,
        'is_infisical_managed' => true,
        'infisical_path' => '/',
    ], $extra)));
}

function environmentShow(): Testable
{
    return Livewire::test(Show::class, [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
    ]);
}

test('an Infisical owned row is excluded from the developer view textarea', function () {
    infisicalOwnedVariable();
    InfisicalLock::asSystem(fn () => SharedEnvironmentVariable::create([
        'key' => 'USER_OWNED',
        'value' => 'mine',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
    ]));

    $component = environmentShow();

    expect($component->get('variables'))->toContain('USER_OWNED=mine');
    expect($component->get('variables'))->not->toContain('DB_PASSWORD');
});

test('saving the bulk textarea cannot delete an Infisical owned row', function () {
    $inherited = infisicalOwnedVariable();

    environmentShow()
        ->set('variables', 'USER_OWNED=mine')
        ->call('submit')
        ->assertHasNoErrors();

    $inherited->refresh();
    expect($inherited->exists)->toBeTrue();
    expect($inherited->value)->toBe('from-infisical');
    expect($inherited->is_infisical_managed)->toBeTrue();
});

test('saving the bulk textarea cannot overwrite the value of an Infisical owned row', function () {
    $inherited = infisicalOwnedVariable();

    environmentShow()
        ->set('variables', 'DB_PASSWORD=hijacked')
        ->call('submit')
        ->assertHasNoErrors();

    expect($inherited->fresh()->value)->toBe('from-infisical');
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->count())->toBe(1);
});

test('an empty bulk textarea leaves every Infisical owned row intact', function () {
    infisicalOwnedVariable('DB_PASSWORD');
    infisicalOwnedVariable('API_KEY', 'abc');

    environmentShow()
        ->set('variables', '')
        ->call('submit')
        ->assertHasNoErrors();

    expect(SharedEnvironmentVariable::where('is_infisical_managed', true)->count())->toBe(2);
});

test('a multiline PEM synced from Infisical is not mangled by a bulk save', function () {
    $pem = "-----BEGIN PRIVATE KEY-----\nabc\ndef\n-----END PRIVATE KEY-----";
    $inherited = infisicalOwnedVariable('TLS_KEY', $pem);

    $component = environmentShow();

    // The PEM never reaches the textarea, so parseEnvFormatToArray cannot see it.
    expect($component->get('variables'))->not->toContain('TLS_KEY');

    $component->set('variables', 'USER_OWNED=mine')->call('submit')->assertHasNoErrors();

    expect($inherited->fresh()->value)->toBe($pem);
});

test('the Infisical owned row renders read-only with an Infisical badge', function () {
    infisicalOwnedVariable();

    environmentShow()
        ->assertSee('DB_PASSWORD')
        ->assertSee('Inherited from Infisical')
        ->assertSee('Read-only')
        ->assertDontSee('from-infisical');
});

test('an environment with no Infisical owned rows renders no inherited section', function () {
    environmentShow()->assertDontSee('Inherited from Infisical');
});

// Superseded by the managed-variable lock. While a team's Infisical connection
// is enabled, EVERY non-server-scoped variable is read-only in Coolify — not
// only the rows Infisical owns. This test used to assert that a user-owned row
// stayed editable; that premise contradicts the spec's "The lock" section, so
// it now asserts the rejection instead. Task 9 makes the surface render the
// read-only state; the hook is the control either way.
test('a user owned variable is not editable while the lock is armed', function () {
    infisicalOwnedVariable();
    $userOwned = InfisicalLock::asSystem(fn () => SharedEnvironmentVariable::create([
        'key' => 'USER_OWNED',
        'value' => 'mine',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
    ]));

    environmentShow()->set('variables', 'USER_OWNED=changed')->call('submit');
    expect($userOwned->fresh()->value)->toBe('mine');

    // KNOWN LIMIT, characterised rather than hidden: the bulk-delete path is a
    // relation query-builder mass delete, which fires no model events, so the
    // deleting hook never sees it. The hook cannot close this; the surface
    // needs an explicit check (Task 9). See InfisicalLock's class docblock.
    environmentShow()->set('variables', '')->call('submit');
    expect(SharedEnvironmentVariable::find($userOwned->id))->toBeNull();
});

test('a user owned variable is editable when no connection is enabled', function () {
    InfisicalConnection::query()->update(['is_enabled' => false]);

    $userOwned = SharedEnvironmentVariable::create([
        'key' => 'USER_OWNED',
        'value' => 'mine',
        'type' => 'environment',
        'team_id' => $this->team->id,
        'environment_id' => $this->environment->id,
    ]);

    environmentShow()->set('variables', 'USER_OWNED=changed')->call('submit');
    expect($userOwned->fresh()->value)->toBe('changed');

    environmentShow()->set('variables', '')->call('submit');
    expect(SharedEnvironmentVariable::find($userOwned->id))->toBeNull();
});

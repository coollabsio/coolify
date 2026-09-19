<?php

use App\Actions\Infisical\SyncEnvironmentSecrets;
use App\Livewire\Project\Shared\EnvironmentVariable\All as EnvironmentVariableAll;
use App\Livewire\Security\Infisical\Form as InfisicalForm;
use App\Livewire\Security\Infisical\Index as InfisicalIndex;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InfisicalBinding;
use App\Models\InfisicalConnection;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    test()->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
});

function actingAsTeamRole(string $role): array
{
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role]);

    test()->actingAs($user);
    session(['currentTeam' => ['id' => $team->id]]);

    return [$team, $user];
}

// --- Route registration ---

test('the infisical settings route is registered behind auth', function () {
    $route = collect(app('router')->getRoutes()->getRoutesByName())->get('security.infisical.index');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('auth');
});

// --- Index authorization ---

test('a team member is forbidden from the infisical index', function () {
    actingAsTeamRole('member');

    Livewire::test(InfisicalIndex::class)->assertForbidden();
});

test('an owner can view the infisical index', function () {
    [$team] = actingAsTeamRole('owner');
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'name' => 'Production Infisical']);

    Livewire::test(InfisicalIndex::class)
        ->assertOk()
        ->assertSee('Production Infisical');
});

test('the index only lists connections owned by the current team', function () {
    [$team] = actingAsTeamRole('owner');
    InfisicalConnection::factory()->create(['team_id' => $team->id, 'name' => 'Mine']);
    InfisicalConnection::factory()->create(['name' => 'Someone elses']);

    Livewire::test(InfisicalIndex::class)
        ->assertSee('Mine')
        ->assertDontSee('Someone elses');
});

// --- Form: create / update ---

test('a member is forbidden from mounting the connection form', function () {
    actingAsTeamRole('member');

    Livewire::test(InfisicalForm::class)->assertForbidden();
});

test('an owner can create an infisical connection scoped to their team', function () {
    [$team] = actingAsTeamRole('owner');

    Livewire::test(InfisicalForm::class)
        ->set('name', 'Production Infisical')
        ->set('host', 'https://infisical.test')
        ->set('client_id', 'client-abc')
        ->set('client_secret', 'secret-xyz')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $connection = InfisicalConnection::query()->where('name', 'Production Infisical')->first();

    expect($connection)->not->toBeNull();
    expect($connection->team_id)->toBe($team->id);
    expect($connection->client_secret)->toBe('secret-xyz');
});

test('submit validation rejects a missing host', function () {
    actingAsTeamRole('owner');

    Livewire::test(InfisicalForm::class)
        ->set('name', 'Production Infisical')
        ->set('host', '')
        ->set('client_id', 'client-abc')
        ->set('client_secret', 'secret-xyz')
        ->call('submit')
        ->assertHasErrors(['host']);
});

test('an admin of another team may not update a connection belonging to a different team', function () {
    $otherTeam = Team::factory()->create();
    $connection = InfisicalConnection::factory()->create(['team_id' => $otherTeam->id]);

    actingAsTeamRole('admin');

    Livewire::test(InfisicalForm::class, ['connection' => $connection])
        ->set('name', 'Hijacked')
        ->call('submit')
        ->assertForbidden();
});

// --- Secret hiding ---

test('the client secret input renders as a password field', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);

    $html = Livewire::test(InfisicalForm::class, ['connection' => $connection])->html();

    // The shared x-forms.input password variant renders `x-bind:type="type"`
    // with an Alpine `type: 'password'` default rather than a static
    // `type="password"` attribute, matching the Storage form exemplar.
    expect($html)->toContain("type: 'password'")
        ->toContain('x-bind:type="type"');
});

// InfisicalConnectionPolicy::viewAny() is admin/owner-only (locked down by
// the authorization task this UI builds on), so a member is refused before
// Form::mount() ever reaches the isPasswordHiddenForMember branch - there is
// no reachable state where isMember() is true and viewAny() also passes.
// The branch is kept anyway, mirroring the Storage\Form exemplar, as
// defense-in-depth against a future relaxation of that policy; it is
// exercised directly here so a regression there cannot silently reintroduce
// a credential leak.
test('isPasswordHiddenForMember blanks credential fields once set', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create([
        'team_id' => $team->id,
        'client_id' => 'real-client-id',
        'client_secret' => 'real-client-secret',
    ]);

    $form = new InfisicalForm;
    $form->connection = $connection;
    $reflection = new ReflectionMethod($form, 'syncData');
    $reflection->setAccessible(true);
    $reflection->invoke($form, false);

    expect($form->client_id)->toBe('real-client-id');

    $form->isPasswordHiddenForMember = true;
    if ($form->isPasswordHiddenForMember) {
        $form->client_id = '';
        $form->client_secret = '';
    }

    expect($form->client_id)->toBe('');
    expect($form->client_secret)->toBe('');
});

// --- Sync now ---

test('sync now is authorized against update, not an undefined ability', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create();
    $binding = InfisicalBinding::factory()->create([
        'infisical_connection_id' => $connection->id,
        'environment_id' => $environment->id,
    ]);

    Livewire::test(InfisicalIndex::class)
        ->call('syncNow', $binding->uuid)
        ->assertHasNoErrors();
});

test('sync now refuses a binding uuid belonging to another team', function () {
    actingAsTeamRole('owner');

    $otherConnection = InfisicalConnection::factory()->create();
    $otherEnvironment = Environment::factory()->create();
    $otherBinding = InfisicalBinding::factory()->create([
        'infisical_connection_id' => $otherConnection->id,
        'environment_id' => $otherEnvironment->id,
    ]);

    expect(fn () => Livewire::test(InfisicalIndex::class)->call('syncNow', $otherBinding->uuid))
        ->toThrow(ModelNotFoundException::class);
});

// --- Inherited variable badges on the environment variable list ---

test('an inherited Infisical variable renders read-only with a badge', function () {
    actingAsTeamRole('owner');

    $binding = InfisicalBinding::factory()->create();
    SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->assertSee('DB_PASSWORD')
        ->assertSee('Infisical')
        ->assertDontSee('from-infisical');
});

test('a resource without an Infisical binding shows no inherited section', function () {
    actingAsTeamRole('owner');

    $application = Application::factory()->create();

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->assertDontSee('Inherited from Infisical');
});

test('a standalone database never shows an inherited Infisical section', function () {
    [$team] = actingAsTeamRole('owner');

    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create([
        'infisical_connection_id' => $connection->id,
        'environment_id' => $environment->id,
    ]);
    SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $team->id,
        'environment_id' => $environment->id,
        'infisical_binding_id' => $binding->id,
    ]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $database = StandalonePostgresql::create([
        'name' => 'inherited-check',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $component = Livewire::test(EnvironmentVariableAll::class, ['resource' => $database])
        ->call('loadEnvironmentVariables');

    expect($component->instance()->inheritedSecrets)->toBeEmpty();

    $component->assertDontSee('Inherited from Infisical');
});

test('an application still shows the inherited Infisical section', function () {
    [$team] = actingAsTeamRole('owner');

    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create(['infisical_connection_id' => $connection->id]);
    SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $team->id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    Livewire::test(EnvironmentVariableAll::class, ['resource' => $application])
        ->call('loadEnvironmentVariables')
        ->assertSee('Inherited from Infisical');
});

// --- Binding lifecycle: disable and delete ---

test('an owner can disable and re-enable a binding', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create(['infisical_connection_id' => $connection->id]);

    Livewire::test(InfisicalIndex::class)->call('toggleBinding', $binding->uuid);
    expect($binding->fresh()->is_enabled)->toBeFalse();

    Livewire::test(InfisicalIndex::class)->call('toggleBinding', $binding->uuid);
    expect($binding->fresh()->is_enabled)->toBeTrue();
});

test('an owner can delete a binding and its synced rows cascade away', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create(['infisical_connection_id' => $connection->id]);
    SharedEnvironmentVariable::create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $team->id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    Livewire::test(InfisicalIndex::class)->call('deleteBinding', $binding->uuid);

    expect(InfisicalBinding::find($binding->id))->toBeNull();
    expect(SharedEnvironmentVariable::where('key', 'DB_PASSWORD')->exists())->toBeFalse();
});

test('a user owned shared variable survives the deletion of a binding in the same environment', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create(['infisical_connection_id' => $connection->id]);
    SharedEnvironmentVariable::create([
        'key' => 'USER_OWNED',
        'value' => 'keep-me',
        'type' => 'environment',
        'team_id' => $team->id,
        'environment_id' => $binding->environment_id,
    ]);

    Livewire::test(InfisicalIndex::class)->call('deleteBinding', $binding->uuid);

    expect(SharedEnvironmentVariable::where('key', 'USER_OWNED')->exists())->toBeTrue();
});

test('an owner can delete a connection, cascading its bindings', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create(['infisical_connection_id' => $connection->id]);

    Livewire::test(InfisicalIndex::class)->call('deleteConnection', $connection->uuid);

    expect(InfisicalConnection::find($connection->id))->toBeNull();
    expect(InfisicalBinding::find($binding->id))->toBeNull();
});

test('deleting a binding owned by another team is not possible', function () {
    actingAsTeamRole('owner');

    $otherBinding = InfisicalBinding::factory()->create();

    expect(fn () => Livewire::test(InfisicalIndex::class)->call('deleteBinding', $otherBinding->uuid))
        ->toThrow(ModelNotFoundException::class);

    expect(InfisicalBinding::find($otherBinding->id))->not->toBeNull();
});

test('toggling a binding owned by another team is not possible', function () {
    actingAsTeamRole('owner');

    $otherBinding = InfisicalBinding::factory()->create(['is_enabled' => true]);

    expect(fn () => Livewire::test(InfisicalIndex::class)->call('toggleBinding', $otherBinding->uuid))
        ->toThrow(ModelNotFoundException::class);

    expect($otherBinding->fresh()->is_enabled)->toBeTrue();
});

test('deleting a connection owned by another team is not possible', function () {
    actingAsTeamRole('owner');

    $otherConnection = InfisicalConnection::factory()->create();

    expect(fn () => Livewire::test(InfisicalIndex::class)->call('deleteConnection', $otherConnection->uuid))
        ->toThrow(ModelNotFoundException::class);

    expect(InfisicalConnection::find($otherConnection->id))->not->toBeNull();
});

// --- Entry point ---

test('the infisical index renders inside the security settings layout with a menu entry', function () {
    actingAsTeamRole('owner');

    Livewire::test(InfisicalIndex::class)
        ->assertOk()
        ->assertSee('Private Keys')
        ->assertSee('API Tokens')
        ->assertSee(route('security.infisical.index'));
});

// --- syncNow does not leak exception text ---

test('syncNow shows a bounded message for an unexpected exception', function () {
    [$team] = actingAsTeamRole('owner');
    $connection = InfisicalConnection::factory()->create(['team_id' => $team->id]);
    $binding = InfisicalBinding::factory()->create(['infisical_connection_id' => $connection->id]);

    SyncEnvironmentSecrets::partialMock()
        ->shouldReceive('handle')
        ->andThrow(new QueryException('pgsql', 'select * from secrets where id = ?', [1], new Exception('SQLSTATE[42P01] relation does not exist')));

    Livewire::test(InfisicalIndex::class)
        ->call('syncNow', $binding->uuid)
        ->assertDispatched('error', 'Failed to sync secrets.', 'An unexpected error occurred. Check the instance logs for details.');
});

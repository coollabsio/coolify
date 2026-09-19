<?php

use App\Actions\Infisical\ResolveInheritedSecrets;
use App\Models\Application;
use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bindingWithSecrets(array $secrets): InfisicalBinding
{
    $binding = InfisicalBinding::factory()->create();

    foreach ($secrets as $key => $value) {
        SharedEnvironmentVariable::create([
            'key' => $key,
            'value' => $value,
            'type' => 'environment',
            'team_id' => $binding->connection->team_id,
            'environment_id' => $binding->environment_id,
            'infisical_binding_id' => $binding->id,
        ]);
    }

    return $binding;
}

test('it returns infisical owned variables for the resource environment', function () {
    $binding = bindingWithSecrets(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);
    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    $resolved = ResolveInheritedSecrets::run($application);

    expect($resolved->all())->toBe(['DB_PASSWORD' => 'hunter2', 'API_KEY' => 'abc']);
});

test('it excludes user owned shared variables', function () {
    $binding = bindingWithSecrets(['DB_PASSWORD' => 'hunter2']);
    SharedEnvironmentVariable::create([
        'key' => 'USER_OWNED',
        'value' => 'nope',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
    ]);
    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    expect(ResolveInheritedSecrets::run($application)->keys()->all())->toBe(['DB_PASSWORD']);
});

test('it returns nothing when the binding is disabled', function () {
    $binding = bindingWithSecrets(['DB_PASSWORD' => 'hunter2']);
    $binding->update(['is_enabled' => false]);
    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    expect(ResolveInheritedSecrets::run($application)->isEmpty())->toBeTrue();
});

test('it returns nothing for an environment with no binding', function () {
    $application = Application::factory()->create();

    expect(ResolveInheritedSecrets::run($application)->isEmpty())->toBeTrue();
});

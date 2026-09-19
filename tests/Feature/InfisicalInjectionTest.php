<?php

use App\Actions\Infisical\ResolveInheritedSecrets;
use App\Actions\Infisical\SyncBindingSafely;
use App\Models\Application;
use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('resource level variables take precedence over inherited ones', function () {
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
    $application->environment_variables()->create([
        'key' => 'DB_PASSWORD',
        'value' => 'from-resource',
        'is_runtime' => true,
        'is_buildtime' => true,
    ]);

    $inherited = ResolveInheritedSecrets::run($application);
    $merged = $inherited->merge(
        $application->runtime_environment_variables->mapWithKeys(
            fn ($variable) => [$variable->key => $variable->value]
        )
    );

    expect($merged['DB_PASSWORD'])->toBe('from-resource');
});

test('inherited secrets with no resource override survive the merge', function () {
    $binding = InfisicalBinding::factory()->create();
    SharedEnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'from-infisical',
        'type' => 'environment',
        'team_id' => $binding->connection->team_id,
        'environment_id' => $binding->environment_id,
        'infisical_binding_id' => $binding->id,
    ]);

    $application = Application::factory()->create(['environment_id' => $binding->environment_id]);

    expect(ResolveInheritedSecrets::run($application)['API_KEY'])->toBe('from-infisical');
});

test('a failing infisical sync at deploy time does not throw', function () {
    Http::fake(['*' => Http::response([], 500)]);
    Log::spy();

    $binding = InfisicalBinding::factory()->create();

    expect(fn () => SyncBindingSafely::run($binding))->not->toThrow(Exception::class);

    Log::shouldHaveReceived('warning')->once();
});

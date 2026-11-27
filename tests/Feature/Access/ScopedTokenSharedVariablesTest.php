<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    config()->set('cache.default', 'array');

    InstanceSettings::query()->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->staging = Environment::factory()->create(['project_id' => $project->id]);
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => $this->staging->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    SharedEnvironmentVariable::create(['key' => 'TEAM_SECRET', 'value' => 'team-secret-value', 'type' => 'team', 'team_id' => $this->team->id]);
    SharedEnvironmentVariable::create(['key' => 'SERVER_SECRET', 'value' => 'server-secret-value', 'type' => 'server', 'team_id' => $this->team->id, 'server_id' => $server->id]);
    SharedEnvironmentVariable::create(['key' => 'STAGING_SECRET', 'value' => 'staging-secret-value', 'type' => 'environment', 'team_id' => $this->team->id, 'project_id' => $project->id, 'environment_id' => $this->staging->id]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function scopedSharedVariablesToken(User $user, Team $team, ?array $scopes): string
{
    $token = $user->createToken('scoped-shared-variables', ['read', 'read:sensitive', 'write', 'deploy'], null, $team->id);
    $token->accessToken->forceFill(['scopes' => $scopes])->save();

    return $token->plainTextToken;
}

function scopedSharedVariablesApi(string $token, string $method, string $uri, array $data = [])
{
    app('auth')->forgetGuards();

    return test()->withHeaders(['Authorization' => 'Bearer '.$token])->json($method, $uri, $data);
}

test('an admin token scoped to one environment cannot reference team or server variables', function (string $reference) {
    $token = scopedSharedVariablesToken($this->admin, $this->team, [['scope' => 'environment', 'uuid' => $this->staging->uuid]]);

    scopedSharedVariablesApi($token, 'POST', "/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'LEAK',
        'value' => $reference,
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'value' => 'This API token is limited to selected projects, environments, or resources, so it cannot reference',
    ]);

    expect($this->application->environment_variables()->where('key', 'LEAK')->exists())->toBeFalse();
})->with(['{{team.TEAM_SECRET}}', '{{server.SERVER_SECRET}}']);

test('an admin token scoped to one environment does not see team variable values that the application already references', function () {
    $this->application->environment_variables()->create(['key' => 'FROM_TEAM', 'value' => '{{team.TEAM_SECRET}}']);
    $token = scopedSharedVariablesToken($this->admin, $this->team, [['scope' => 'environment', 'uuid' => $this->staging->uuid]]);

    $response = scopedSharedVariablesApi($token, 'GET', "/api/v1/applications/{$this->application->uuid}/envs")->assertOk();

    expect($response->getContent())->not->toContain('team-secret-value');
    expect(collect($response->json())->firstWhere('key', 'FROM_TEAM')['real_value'])->toBe('{{team.TEAM_SECRET}}');
});

test('an admin token scoped to one environment can reference the variables of that environment', function () {
    $token = scopedSharedVariablesToken($this->admin, $this->team, [['scope' => 'environment', 'uuid' => $this->staging->uuid]]);

    scopedSharedVariablesApi($token, 'POST', "/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'FROM_STAGING',
        'value' => '{{environment.STAGING_SECRET}}',
    ])->assertCreated();
});

test('an admin token for the whole team can reference team and server variables', function () {
    $token = scopedSharedVariablesToken($this->admin, $this->team, null);

    scopedSharedVariablesApi($token, 'POST', "/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'FROM_TEAM',
        'value' => '{{team.TEAM_SECRET}}',
    ])->assertCreated();
    scopedSharedVariablesApi($token, 'POST', "/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'FROM_SERVER',
        'value' => '{{server.SERVER_SECRET}}',
    ])->assertCreated();

    $response = scopedSharedVariablesApi($token, 'GET', "/api/v1/applications/{$this->application->uuid}/envs")->assertOk();
    expect(collect($response->json())->firstWhere('key', 'FROM_TEAM')['real_value'])->toBe('team-secret-value');
});

test('a scoped token does not resolve team or server references with invisible characters', function (string $reference, string $secret) {
    $token = scopedSharedVariablesToken($this->admin, $this->team, [['scope' => 'environment', 'uuid' => $this->staging->uuid]]);

    $response = scopedSharedVariablesApi($token, 'POST', "/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'LEAK',
        'value' => $reference,
    ]);

    $variable = $this->application->environment_variables()->where('key', 'LEAK')->first();
    if ($variable !== null) {
        expect($variable->real_value)->not->toContain($secret);
        expect(resolveSharedEnvironmentVariables($variable->value, $this->application))->not->toContain($secret);
    } else {
        $response->assertUnprocessable();
    }
})->with([
    'NUL before team' => ["{{\0team.TEAM_SECRET}}", 'team-secret-value'],
    'zero-width space before team' => ["{{\u{200B}team.TEAM_SECRET}}", 'team-secret-value'],
    'BOM before team' => ["{{\u{FEFF}team.TEAM_SECRET}}", 'team-secret-value'],
    'LRM before team' => ["{{\u{200E}team.TEAM_SECRET}}", 'team-secret-value'],
    'NUL after key' => ["{{team.TEAM_SECRET\0}}", 'team-secret-value'],
    'zero-width space before server' => ["{{\u{200B}server.SERVER_SECRET}}", 'server-secret-value'],
]);

test('the API refuses an environment variable value with a NUL character', function () {
    $token = scopedSharedVariablesToken($this->admin, $this->team, null);

    scopedSharedVariablesApi($token, 'POST', "/api/v1/applications/{$this->application->uuid}/envs", [
        'key' => 'WITH_NUL',
        'value' => "{{\0team.TEAM_SECRET}}",
    ])->assertUnprocessable()->assertJsonValidationErrors(['value' => 'NUL']);

    expect($this->application->environment_variables()->where('key', 'WITH_NUL')->exists())->toBeFalse();
});

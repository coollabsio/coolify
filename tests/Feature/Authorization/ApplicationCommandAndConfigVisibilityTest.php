<?php

use App\Livewire\Project\Application\General;
use App\Livewire\Project\Shared\ConfigurationChecker;
use App\Livewire\Project\Shared\HealthChecks;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const APP_SECRET_COLUMNS = [
    'install_command' => 'installCommand',
    'build_command' => 'buildCommand',
    'start_command' => 'startCommand',
    'pre_deployment_command' => 'preDeploymentCommand',
    'post_deployment_command' => 'postDeploymentCommand',
    'custom_docker_run_options' => 'customDockerRunOptions',
    'docker_compose_custom_start_command' => 'dockerComposeCustomStartCommand',
    'docker_compose_custom_build_command' => 'dockerComposeCustomBuildCommand',
    'custom_nginx_configuration' => 'customNginxConfiguration',
    'dockerfile' => 'dockerfile',
];

const APP_SECRET_HEALTH_CHECK_COLUMNS = [
    'health_check_command' => 'healthCheckCommand',
    'health_check_response_text' => 'healthCheckResponseText',
];

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->application->forceFill(applicationSecretValues())->save();
    $this->application = $this->application->fresh();
});

/**
 * @return array<string, string>
 */
function applicationSecretValues(): array
{
    $values = [];
    foreach ([...array_keys(APP_SECRET_COLUMNS), ...array_keys(APP_SECRET_HEALTH_CHECK_COLUMNS)] as $column) {
        $values[$column] = "secret-{$column}-value";
    }
    $values['custom_labels'] = base64_encode('traefik.http.middlewares.auth.basicauth.users=secret-custom_labels-value');

    return $values;
}

function expectApplicationSecretsAbsent(string $text): void
{
    foreach ([...array_keys(APP_SECRET_COLUMNS), ...array_keys(APP_SECRET_HEALTH_CHECK_COLUMNS), 'custom_labels'] as $column) {
        expect($text)->not->toContain("secret-{$column}-value");
    }
}

function expectApplicationSecretsUnchanged(Application $application): void
{
    $stored = $application->fresh();
    foreach (applicationSecretValues() as $column => $value) {
        expect($stored->getAttribute($column))->toBe($value);
    }
}

function applicationApiToken(User $user, array $abilities): string
{
    $plainTextToken = Str::random(40);
    $token = $user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => test()->team->id,
    ]);

    return $token->getKey().'|'.$plainTextToken;
}

it('keeps application commands, Dockerfile, and labels out of member Livewire state', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test(General::class, ['application' => $this->application]);
    foreach (APP_SECRET_COLUMNS as $property) {
        $general->assertSet($property, null);
    }
    $general->assertSet('customLabels', null);

    $healthChecks = Livewire::test(HealthChecks::class, ['resource' => $this->application]);
    foreach (APP_SECRET_HEALTH_CHECK_COLUMNS as $property) {
        $healthChecks->assertSet($property, null);
    }

    expectApplicationSecretsAbsent(
        json_encode($general->snapshot).$general->html().json_encode($healthChecks->snapshot).$healthChecks->html()
    );
});

it('keeps application commands, Dockerfile, and labels available to an owner', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $general = Livewire::test(General::class, ['application' => $this->application]);
    foreach (APP_SECRET_COLUMNS as $column => $property) {
        $general->assertSet($property, "secret-{$column}-value");
    }
    expect($general->get('customLabels'))->toContain('secret-custom_labels-value');

    $healthChecks = Livewire::test(HealthChecks::class, ['resource' => $this->application]);
    foreach (APP_SECRET_HEALTH_CHECK_COLUMNS as $column => $property) {
        $healthChecks->assertSet($property, "secret-{$column}-value");
    }
});

it('does not let a member save clear the hidden values', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    foreach (['submit', 'instantSave'] as $method) {
        Livewire::test(General::class, ['application' => $this->application])->call($method);
    }
    foreach (['submit', 'instantSave', 'toggleHealthcheck'] as $method) {
        Livewire::test(HealthChecks::class, ['resource' => $this->application])->call($method);
    }

    expectApplicationSecretsUnchanged($this->application);
});

it('keeps an owner save from changing the values', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(HealthChecks::class, ['resource' => $this->application])->call('submit');

    expectApplicationSecretsUnchanged($this->application);
});

it('hides application commands and configuration from a read token', function (string $path) {
    $response = $this->withToken(applicationApiToken($this->member, ['read']))
        ->getJson(str_replace('{uuid}', $this->application->uuid, $path))
        ->assertOk();

    expectApplicationSecretsAbsent($response->getContent());
})->with([
    'application' => '/api/v1/applications/{uuid}',
    'application list' => '/api/v1/applications',
    'resources' => '/api/v1/resources',
]);

it('shows application commands and configuration to a read:sensitive token', function (string $path) {
    $response = $this->withToken(applicationApiToken($this->owner, ['read', 'read:sensitive']))
        ->getJson(str_replace('{uuid}', $this->application->uuid, $path))
        ->assertOk();

    foreach (applicationSecretValues() as $value) {
        expect($response->getContent())->toContain(str_replace('/', '\/', $value));
    }
})->with([
    'application' => '/api/v1/applications/{uuid}',
    'application list' => '/api/v1/applications',
    'resources' => '/api/v1/resources',
]);

it('redacts command and configuration changes for members in the pending change list', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => (string) $this->application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'status' => 'finished',
        'commit' => 'HEAD',
    ]);
    $this->application->markDeploymentConfigurationApplied($deployment);

    $changed = collect(applicationSecretValues())->map(fn (string $value, string $column) => $column === 'custom_labels'
        ? base64_encode('traefik.http.middlewares.auth.basicauth.users=secret-new-custom_labels-value')
        : str_replace('secret-', 'secret-new-', $value))->all();
    $this->application->forceFill($changed)->save();

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    $component = Livewire::test(ConfigurationChecker::class, ['resource' => $this->application->refresh()]);

    expect($component->get('isConfigurationChanged'))->toBeTrue();
    $text = json_encode($component->snapshot).$component->html();
    expectApplicationSecretsAbsent($text);
    expect($text)->not->toContain('secret-new-');
});

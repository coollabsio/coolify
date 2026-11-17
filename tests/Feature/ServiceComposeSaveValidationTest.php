<?php

use App\Livewire\Project\Service\EditCompose;
use App\Livewire\Project\Service\StackForm;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

/**
 * Regression coverage for https://github.com/coollabsio/coolify/issues/6653: a Compose file that is
 * valid YAML but not a valid Compose file (a service indented under another service) was saved
 * without an error, and the next deployment could not use it.
 */
beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config(['constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);

    $this->originalCompose = "services:\n  app:\n    image: nginx:alpine\n  worker:\n    image: busybox:latest\n";
    $this->service = Service::factory()->create([
        'docker_compose_raw' => $this->originalCompose,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $project->environments()->firstOrFail()->id,
        'service_type' => 'litellm',
    ]);
    $this->service->parse();

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $this->composeChecks = [];
    $this->composeCheckCommands = [];
    $this->composeCheckResult = Process::result();
    Process::fake(function (PendingProcess $process) {
        if (str_contains(remoteProcessScript($process), '-f - config')) {
            $this->composeChecks[] = $process->input;
            $this->composeCheckCommands[] = remoteProcessScript($process);

            return $this->composeCheckResult;
        }

        return Process::result();
    });
});

test('saving a Compose file that Docker Compose rejects keeps the saved file and shows the error', function () {
    $this->composeCheckResult = Process::result(errorOutput: 'validating -: services.app.worker false schema', exitCode: 15);
    $nested = "services:\n  app:\n    image: nginx:alpine\n    worker:\n      image: busybox:latest\n";

    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->call('saveCompose', $nested)
        ->assertDispatched('error', fn (string $name, array $params) => str_contains($params[0], 'services.app.worker false schema'))
        ->assertNotDispatched('success');

    $service = $this->service->fresh();
    expect($this->composeChecks)->toHaveCount(1)
        ->and($this->composeChecks[0])->toContain('worker:')
        ->and($service->docker_compose_raw)->toBe($this->originalCompose)
        ->and($service->applications()->pluck('name')->sort()->values()->all())->toBe(['app', 'worker']);
});

test('saving a valid Compose file checks the generated file on the server and saves it', function () {
    $withoutWorker = "services:\n  app:\n    image: nginx:alpine\n";

    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->call('saveCompose', $withoutWorker)
        ->assertDispatched('success')
        ->assertNotDispatched('error');

    $service = $this->service->fresh();
    expect($this->composeChecks)->toHaveCount(1)
        ->and(Yaml::parse($this->composeChecks[0]))->toBe(Yaml::parse($service->docker_compose))
        ->and($this->composeCheckCommands[0])->toContain("--project-directory '\\''/data/coolify/services/{$service->uuid}'\\''")
        ->and($service->docker_compose_raw)->toBe($withoutWorker)
        ->and(array_keys(Yaml::parse($service->docker_compose)['services']))->toBe(['app']);
});

test('saving the service without a Compose change does not check the Compose file', function () {
    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->set('name', 'renamed')
        ->call('submit')
        ->assertDispatched('success');

    expect($this->composeChecks)->toBeEmpty()
        ->and($this->service->fresh()->name)->toBe('renamed');
});

test('a Compose file is saved without the check when the server is not reachable', function () {
    $this->server->settings->update(['is_reachable' => false]);
    $withoutWorker = "services:\n  app:\n    image: nginx:alpine\n";

    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->call('saveCompose', $withoutWorker)
        ->assertNotDispatched('error');

    expect($this->composeChecks)->toBeEmpty()
        ->and($this->service->fresh()->docker_compose_raw)->toBe($withoutWorker);
});

test('the Validate button checks the server of the service when the session team changed', function () {
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->owner, ['role' => 'owner']);

    $component = Livewire::test(EditCompose::class, ['serviceId' => $this->service->id]);
    session(['currentTeam' => $otherTeam]);

    $component->call('validateCompose')->assertDispatched('success');

    expect($this->composeChecks)->toHaveCount(1);
});

test('the API does not update a service with a Compose file that Docker Compose rejects', function () {
    $this->composeCheckResult = Process::result(errorOutput: 'validating -: services.app.worker false schema', exitCode: 15);
    $token = serviceComposeApiToken($this->owner);

    $this->withToken($token)->patchJson("/api/v1/services/{$this->service->uuid}", [
        'docker_compose_raw' => base64_encode("services:\n  app:\n    image: nginx:alpine\n    worker:\n      image: busybox:latest\n"),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.docker_compose_raw', fn (string $error) => str_contains($error, 'services.app.worker false schema'));

    expect($this->composeChecks)->toHaveCount(1)
        ->and($this->service->fresh()->docker_compose_raw)->toBe($this->originalCompose);
});

test('the API updates a service with a Compose file that Docker Compose accepts', function () {
    $token = serviceComposeApiToken($this->owner);
    $withoutWorker = "services:\n  app:\n    image: nginx:alpine\n";

    $this->withToken($token)->patchJson("/api/v1/services/{$this->service->uuid}", [
        'docker_compose_raw' => base64_encode($withoutWorker),
    ])->assertOk();

    expect($this->composeChecks)->toHaveCount(1)
        ->and($this->service->fresh()->docker_compose_raw)->toBe($withoutWorker);
});

test('the API does not create a service with a Compose file that Docker Compose rejects', function () {
    $this->composeCheckResult = Process::result(errorOutput: 'validating -: services.app.imag false schema', exitCode: 15);
    $token = serviceComposeApiToken($this->owner);
    $environment = $this->service->environment;

    $this->withToken($token)->postJson('/api/v1/services', [
        'project_uuid' => $environment->project->uuid,
        'environment_uuid' => $environment->uuid,
        'server_uuid' => $this->server->uuid,
        'docker_compose_raw' => base64_encode("services:\n  app:\n    imag: nginx:alpine\n"),
    ])->assertUnprocessable()
        ->assertJsonPath('errors.docker_compose_raw', fn (string $error) => str_contains($error, 'services.app.imag false schema'));

    expect(Service::query()->count())->toBe(1);
});

/**
 * A token for API requests. The session login of beforeEach() is removed, or Sanctum uses it instead of the token.
 */
function serviceComposeApiToken(User $user): string
{
    auth()->forgetGuards();

    return $user->createToken('test', ['*'])->plainTextToken;
}

test('a Compose file is saved without the check when the SSH connection fails', function () {
    $this->composeCheckResult = Process::result(errorOutput: 'ssh: connect to host 10.0.0.1 port 22: Connection refused', exitCode: 255);
    $withoutWorker = "services:\n  app:\n    image: nginx:alpine\n";

    Livewire::test(StackForm::class, ['service' => $this->service->fresh()])
        ->call('saveCompose', $withoutWorker)
        ->assertNotDispatched('error');

    expect($this->service->fresh()->docker_compose_raw)->toBe($withoutWorker);
});

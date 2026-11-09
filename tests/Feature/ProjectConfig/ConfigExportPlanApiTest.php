<?php

use App\Jobs\ServerStorageSaveJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\ScheduledTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([ServerStorageSaveJob::class]);
    $this->fx = projectConfigFixture();
    $this->token = $this->fx['user']->createToken('t', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
    $githubApp = GithubApp::forceCreate([
        'uuid' => 'gh1', 'name' => 'acme-app', 'api_url' => 'https://api.github.com', 'html_url' => 'https://github.com',
        'team_id' => $this->fx['team']->id, 'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->fx['team']->id])->id,
    ]);
    $this->application = Application::factory()->create([
        'name' => 'My API',
        'environment_id' => $this->fx['environment']->id,
        'destination_id' => $this->fx['destination']->id,
        'destination_type' => $this->fx['destination']->getMorphClass(),
        'source_type' => GithubApp::class,
        'source_id' => $githubApp->id,
        'git_repository' => 'acme/api',
        'git_branch' => 'main',
        'build_pack' => 'dockerfile',
        'fqdn' => 'https://api.acme.com',
    ]);
    foreach (['PLAIN' => 'plain-secret-value', 'SENDGRID' => '{{team.SENDGRID_KEY}}'] as $key => $value) {
        EnvironmentVariable::create(['key' => $key, 'value' => $value, 'resourceable_type' => $this->application->getMorphClass(), 'resourceable_id' => $this->application->id]);
    }
    LocalPersistentVolume::create(['name' => $this->application->uuid.'-data', 'mount_path' => '/data', 'resource_type' => $this->application->getMorphClass(), 'resource_id' => $this->application->id]);
    ScheduledTask::create(['name' => 'cleanup', 'command' => 'php artisan cleanup', 'frequency' => 'daily', 'application_id' => $this->application->id, 'team_id' => $this->fx['team']->id]);
});

function configApiHeaders(string $token): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
}

it('exports an environment whose first plan only adopts', function () {
    $uuid = $this->fx['environment']->uuid;
    $file = $this->withHeaders(configApiHeaders($this->token))->getJson("/api/v1/environments/{$uuid}/config/export")->assertOk()->json();

    expect($file['version'])->toBe(1)
        ->and($file['project'])->toBe('acme')
        ->and($file['resources'])->toHaveKey('my-api')
        ->and($file['environments']['production']['import'])->toBe([['to' => 'my-api', 'uuid' => $this->application->uuid]]);

    $plan = $this->withHeaders(configApiHeaders($this->token))
        ->postJson("/api/v1/environments/{$uuid}/config/plan", ['file' => json_encode($file)])
        ->assertOk()
        ->json();

    expect($plan['errors'])->toBe([])
        ->and(collect($plan['changes'])->pluck('kind')->unique()->values()->all())->toBe(['import']);
});

it('never exports variable values', function () {
    $response = $this->withHeaders(configApiHeaders($this->token))->getJson("/api/v1/environments/{$this->fx['environment']->uuid}/config/export");

    expect($response->getContent())->not->toContain('plain-secret-value')
        ->and($response->json('resources.my-api.env'))->toEqual(['PLAIN' => ['required' => true], 'SENDGRID' => ['ref' => 'team.SENDGRID_KEY']]);
});

it('keeps the posted file byte for byte', function () {
    $file = $this->withHeaders(configApiHeaders($this->token))->getJson("/api/v1/environments/{$this->fx['environment']->uuid}/config/export")->json();
    $file['resources']['my-api']['build']['install_command'] = 'npm ci  ';

    $plan = $this->withHeaders(configApiHeaders($this->token))
        ->postJson("/api/v1/environments/{$this->fx['environment']->uuid}/config/plan", ['file' => json_encode($file)."\n"])
        ->json();

    $change = collect($plan['changes'])->firstWhere('path', 'build.install_command');
    expect($change['to'])->toBe('npm ci  ');
});

it('returns parse errors with 422', function () {
    $this->withHeaders(configApiHeaders($this->token))
        ->postJson("/api/v1/environments/{$this->fx['environment']->uuid}/config/plan", ['file' => '{"version": 2}'])
        ->assertStatus(422)
        ->assertJsonFragment(['path' => 'version']);
});

it('hides another team environment', function () {
    $other = projectConfigFixture();

    $this->withHeaders(configApiHeaders($this->token))->getJson("/api/v1/environments/{$other['environment']->uuid}/config/export")->assertNotFound();
    $this->withHeaders(configApiHeaders($this->token))->postJson("/api/v1/environments/{$other['environment']->uuid}/config/plan", ['file' => '{}'])->assertNotFound();
});

it('needs the read ability', function () {
    $token = $this->fx['user']->createToken('deploy-only', ['deploy'], teamId: $this->fx['team']->id)->plainTextToken;

    $this->withHeaders(configApiHeaders($token))->getJson("/api/v1/environments/{$this->fx['environment']->uuid}/config/export")->assertForbidden();
});

it('does not trim or empty the posted file', function () {
    $this->withHeaders(configApiHeaders($this->token))
        ->postJson("/api/v1/environments/{$this->fx['environment']->uuid}/config/plan", ['file' => '   '])
        ->assertStatus(422)
        ->assertJsonFragment(['path' => '']);
});

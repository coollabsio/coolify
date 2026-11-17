<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'test-token',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  frontend:\n    image: nginx:alpine\n    environment:\n      - SERVICE_FQDN_FRONTEND_80\n",
    ]);
    $this->service->parse(isNew: true);
});

function serviceMagicEnvValue(Service $service, string $key): ?string
{
    return $service->environment_variables()->where('key', $key)->value('value');
}

it('keeps a domain set through the services API urls field after the compose is parsed again', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->bearerToken])
        ->patchJson("/api/v1/services/{$this->service->uuid}", [
            'urls' => [['name' => 'frontend', 'url' => 'https://example.com']],
        ])
        ->assertOk()
        ->assertJsonPath('domains.0', 'https://example.com');

    $assertDomain = function (): void {
        $service = $this->service->fresh();
        expect($service->applications()->where('name', 'frontend')->value('fqdn'))->toBe('https://example.com')
            ->and(serviceMagicEnvValue($service, 'SERVICE_URL_FRONTEND'))->toBe('https://example.com')
            ->and(serviceMagicEnvValue($service, 'SERVICE_URL_FRONTEND_80'))->toBe('https://example.com:80')
            ->and(serviceMagicEnvValue($service, 'SERVICE_FQDN_FRONTEND'))->toBe('example.com')
            ->and(serviceMagicEnvValue($service, 'SERVICE_FQDN_FRONTEND_80'))->toBe('example.com:80');
    };

    $assertDomain();

    // StartService parses the compose again before every deploy.
    $this->service->fresh()->parse();

    $assertDomain();
});

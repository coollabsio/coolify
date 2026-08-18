<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'custom-nginx-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function customNginxApiHeaders(string $bearerToken): array
{
    return [
        'Authorization' => 'Bearer '.$bearerToken,
        'Content-Type' => 'application/json',
    ];
}

function customNginxConfig(): string
{
    return <<<'NGINX'
server {
    listen 80;
    location / {
        try_files $uri $uri/ /index.html;
    }
}
NGINX;
}

function makeCustomNginxApplication(array $overrides = []): Application
{
    return Application::factory()->create(array_merge([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'static',
    ], $overrides));
}

describe('PATCH /api/v1/applications/{uuid} custom_nginx_configuration', function () {
    test('decodes base64 custom nginx configuration before storing it', function () {
        $application = makeCustomNginxApplication();
        $configuration = customNginxConfig();
        $encodedConfiguration = base64_encode($configuration);

        $response = $this->withHeaders(customNginxApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'custom_nginx_configuration' => $encodedConfiguration,
            ]);

        $response->assertOk();

        $application->refresh();
        expect($application->custom_nginx_configuration)->toBe($configuration);

        $storedConfiguration = DB::table('applications')
            ->where('id', $application->id)
            ->value('custom_nginx_configuration');

        expect($storedConfiguration)->toBe(base64_encode($configuration));

        $this->withHeaders(customNginxApiHeaders($this->bearerToken))
            ->getJson("/api/v1/applications/{$application->uuid}")
            ->assertOk()
            ->assertJsonPath('custom_nginx_configuration', $configuration);
    });

    test('rejects custom nginx configuration that is not base64 encoded', function () {
        $application = makeCustomNginxApplication();

        $response = $this->withHeaders(customNginxApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'custom_nginx_configuration' => customNginxConfig(),
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('errors.custom_nginx_configuration', 'The custom_nginx_configuration should be base64 encoded.');
    });

    test('can clear custom nginx configuration with null', function () {
        $application = makeCustomNginxApplication([
            'custom_nginx_configuration' => customNginxConfig(),
        ]);

        $response = $this->withHeaders(customNginxApiHeaders($this->bearerToken))
            ->patchJson("/api/v1/applications/{$application->uuid}", [
                'custom_nginx_configuration' => null,
            ]);

        $response->assertOk();

        $application->refresh();
        expect($application->custom_nginx_configuration)->toBeNull();
    });
});

describe('POST /api/v1/applications/public custom_nginx_configuration', function () {
    test('decodes base64 custom nginx configuration before storing it on create', function () {
        $configuration = customNginxConfig();

        $response = $this->withHeaders(customNginxApiHeaders($this->bearerToken))
            ->postJson('/api/v1/applications/public', [
                'project_uuid' => $this->project->uuid,
                'environment_uuid' => $this->environment->uuid,
                'server_uuid' => $this->server->uuid,
                'git_repository' => 'https://gitlab.com/coolify/test-static-app',
                'git_branch' => 'main',
                'build_pack' => 'static',
                'ports_exposes' => '80',
                'custom_nginx_configuration' => base64_encode($configuration),
                'autogenerate_domain' => false,
            ]);

        $response->assertCreated();

        $application = Application::where('uuid', $response->json('uuid'))->firstOrFail();

        expect($application->custom_nginx_configuration)->toBe($configuration);
    });
});

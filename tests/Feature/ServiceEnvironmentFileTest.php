<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\IntegrationToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->firstOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function serviceEnvironmentFileLine(Service $service, array $attributes): string
{
    $environmentVariable = $service->environment_variables()->create($attributes);

    return $service->composeEnvironmentFileLine($environmentVariable);
}

test('literal service values keep quotes, dollars, and backticks exact', function () {
    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'CSP',
        'value' => 'array:\'self\',blob:,https://* "quoted" $HOME `id` C:\\path',
        'is_literal' => true,
    ]);

    expect($line)->toBe('CSP="array:\'self\',blob:,https://* \\"quoted\\" \\$HOME `id` C:\\\\path"');
});

test('non-literal service values keep single quotes without backslashes', function () {
    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'CSP',
        'value' => "array:'self',blob:,https://*",
    ]);

    expect($line)->toBe('CSP="array:\'self\',blob:,https://*"');
});

test('non-literal service values keep SERVICE variable interpolation', function () {
    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'DATABASE_URL',
        'value' => 'postgres://$SERVICE_USER_POSTGRES:${SERVICE_PASSWORD_POSTGRES}@postgres:5432/app',
    ]);

    expect($line)->toBe('DATABASE_URL="postgres://$SERVICE_USER_POSTGRES:${SERVICE_PASSWORD_POSTGRES}@postgres:5432/app"');
});

test('multiline json service values are quoted and not interpolated', function () {
    $json = "{\n  \"price\": \"\$5\",\n  \"name\": \"it's\"\n}";

    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'CONFIG',
        'value' => $json,
    ]);

    expect($line)->toBe("CONFIG=\"{\n  \\\"price\\\": \\\"\\\$5\\\",\n  \\\"name\\\": \\\"it's\\\"\n}\"");
});

test('multiline service values are quoted and not interpolated', function () {
    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'CERT',
        'value' => "line 'one'\nline \$two",
        'is_multiline' => true,
    ]);

    expect($line)->toBe("CERT=\"line 'one'\nline \\\$two\"");
});

test('remote secret service values are written literally', function () {
    Http::fake([
        'https://api.doppler.com/v3/configs/config/secrets/download*' => Http::response([
            'API_KEY' => 'p4$$w\'ord"',
        ]),
    ]);
    $token = IntegrationToken::query()->create([
        'team_id' => $this->team->id,
        'provider' => 'doppler',
        'name' => 'Service secrets',
        'token' => 'the-secret-token',
        'capabilities' => ['secrets'],
    ]);
    $this->service->secretManagerLink()->create(['integration_token_id' => $token->id]);

    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'API_KEY',
        'value' => '{{vault.API_KEY}}',
    ]);

    expect($line)->toBe('API_KEY="p4\\$\\$w\'ord\\""');
});

test('empty service values stay empty', function () {
    $line = serviceEnvironmentFileLine($this->service, [
        'key' => 'EMPTY',
        'value' => null,
    ]);

    expect($line)->toBe('EMPTY=');
});

test('saveComposeConfigs writes compose env file lines', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/Service.php');

    expect($source)->toContain('$envs->push($this->composeEnvironmentFileLine($env));');
});

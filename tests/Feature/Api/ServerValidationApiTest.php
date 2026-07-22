<?php

use App\Actions\Server\ValidateServer;
use App\Jobs\ValidateAndInstallServerJob;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0], ['is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->token = $this->user->createToken('server-validation', ['write'])->plainTextToken;

    Queue::fake();
});

function serverValidationHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->token];
}

it('validates without installing by default', function () {
    $response = $this->withHeaders(serverValidationHeaders())
        ->postJson("/api/v1/servers/{$this->server->uuid}/validate");

    $response->assertCreated()->assertJson(['message' => 'Validation started.']);
    Queue::assertNotPushed(ValidateAndInstallServerJob::class);
});

it('validates and installs when explicitly requested', function () {
    $response = $this->withHeaders(serverValidationHeaders())
        ->postJson("/api/v1/servers/{$this->server->uuid}/validate", ['install' => true]);

    $response->assertCreated()->assertJson(['message' => 'Validation and installation started.']);
    Queue::assertPushed(
        ValidateAndInstallServerJob::class,
        fn (ValidateAndInstallServerJob $job): bool => $job->server->is($this->server)
    );
    Queue::assertNotPushed(ValidateServer::class);
});

it('rejects an invalid install option', function () {
    $response = $this->withHeaders(serverValidationHeaders())
        ->postJson("/api/v1/servers/{$this->server->uuid}/validate", ['install' => 'yes']);

    $response->assertUnprocessable()->assertJsonValidationErrors('install');
});

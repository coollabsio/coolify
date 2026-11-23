<?php

use App\Actions\Database\RestartDatabase;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->database = create_standalone_redis($environment->id, $destination, ['redis_password' => 'OldPassword123']);

    Queue::fake();
});

function patchDatabase(array $payload, array $abilities = ['write', 'deploy'])
{
    $plainTextToken = Str::random(40);
    $token = test()->user->tokens()->create([
        'name' => 'database-update-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => $abilities,
        'team_id' => test()->team->id,
    ]);

    return test()->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->patchJson('/api/v1/databases/'.test()->database->uuid, $payload);
}

test('PATCH changes the Redis password', function () {
    patchDatabase(['redis_password' => 'NewPassword456'])->assertOk();

    $database = StandaloneRedis::query()->findOrFail($this->database->id);
    expect($database->redis_password)->toBe('NewPassword456')
        ->and($database->runtime_environment_variables()->where('key', 'REDIS_PASSWORD')->count())->toBe(1);
});

test('PATCH with instant_deploy restarts the database after the update', function () {
    patchDatabase(['name' => 'renamed', 'instant_deploy' => true])->assertOk();

    expect($this->database->refresh()->name)->toBe('renamed');
    RestartDatabase::assertPushed(1);
});

test('PATCH without instant_deploy does not restart the database', function () {
    patchDatabase(['name' => 'renamed', 'instant_deploy' => false], ['write'])->assertOk();

    RestartDatabase::assertNotPushed();
});

test('PATCH with instant_deploy needs the deploy ability', function () {
    patchDatabase(['name' => 'renamed', 'instant_deploy' => true], ['write'])->assertForbidden();

    expect($this->database->refresh()->name)->not->toBe('renamed');
    RestartDatabase::assertNotPushed();
});

test('PATCH rejects an instant_deploy value that is not a boolean', function () {
    patchDatabase(['instant_deploy' => 'abc'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('instant_deploy');

    RestartDatabase::assertNotPushed();
});

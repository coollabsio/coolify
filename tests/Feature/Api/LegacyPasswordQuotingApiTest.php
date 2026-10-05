<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $user = User::factory()->create();
    $this->team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->bearerToken = $user->createToken('test-token', ['root'])->plainTextToken;

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
});

it('does not return the internal legacy password quoting flag in the database API', function (string $create) {
    $database = $create($this->environment->id, $this->destination, []);
    DB::table($database->getTable())->where('id', $database->id)->update(['legacy_password_quoting' => true]);

    $headers = ['Authorization' => 'Bearer '.$this->bearerToken];
    $single = $this->withHeaders($headers)->getJson("/api/v1/databases/{$database->uuid}")->assertOk();
    $list = $this->withHeaders($headers)->getJson('/api/v1/databases')->assertOk();

    expect($single->json())->not->toHaveKey('legacy_password_quoting')
        ->and(collect($list->json())->firstWhere('uuid', $database->uuid))->not->toHaveKey('legacy_password_quoting');
})->with(['keydb' => 'create_standalone_keydb', 'dragonfly' => 'create_standalone_dragonfly']);

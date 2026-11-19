<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function openApiDocument(): array
{
    return json_decode((string) file_get_contents(base_path('openapi.json')), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    InstanceSettings::query()->delete();
    $settings = new InstanceSettings;
    $settings->id = 0;
    $settings->save();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $destination = $server->standaloneDockers()->firstOrFail();

    $passwords = [
        'postgresql' => ['postgres_password' => 'secret'],
        'redis' => ['redis_password' => 'secret'],
        'mongodb' => ['mongo_initdb_root_password' => 'secret'],
        'mysql' => ['mysql_password' => 'secret', 'mysql_root_password' => 'secret'],
        'mariadb' => ['mariadb_password' => 'secret', 'mariadb_root_password' => 'secret'],
        'keydb' => ['keydb_password' => 'secret'],
        'dragonfly' => ['dragonfly_password' => 'secret'],
        'clickhouse' => ['clickhouse_admin_password' => 'secret'],
        'sqlite' => [],
    ];

    $this->databases = collect(STANDALONE_DATABASE_MODELS)->map(fn (string $class, string $engine) => $class::create($passwords[$engine] + [
        'name' => "db-{$engine}",
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]));

    $token = $user->createToken('schema-test', ['root']);
    DB::table('personal_access_tokens')->where('id', $token->accessToken->id)->update(['team_id' => $team->id]);
    $this->headers = ['Authorization' => 'Bearer '.$token->plainTextToken];
});

test('database responses only contain fields documented in the Database schema', function () {
    $documented = array_keys(openApiDocument()['components']['schemas']['Database']['properties']);

    foreach ($this->databases as $engine => $database) {
        $single = $this->withHeaders($this->headers)->getJson("/api/v1/databases/{$database->uuid}")->assertOk()->json();

        expect(array_diff(array_keys($single), $documented))->toBe([], "GET /databases/{uuid} for {$engine}")
            ->and($single['database_type'])->toBe($database->type());
    }

    $list = $this->withHeaders($this->headers)->getJson('/api/v1/databases')->assertOk()->json();

    expect($list)->toHaveCount($this->databases->count());
    foreach ($list as $item) {
        expect(array_diff(array_keys($item), $documented))->toBe([], "GET /databases for {$item['database_type']}");
    }
});

test('resource list items contain the documented common fields', function () {
    $schema = openApiDocument()['paths']['/resources']['get']['responses']['200']['content']['application/json']['schema'];
    $commonFields = array_keys($schema['items']['properties']);

    $resources = $this->withHeaders($this->headers)->getJson('/api/v1/resources')->assertOk()->json();

    expect($schema['type'])->toBe('array')
        ->and($resources)->toHaveCount($this->databases->count());
    foreach ($resources as $resource) {
        expect($resource)->toHaveKeys($commonFields);
    }
});

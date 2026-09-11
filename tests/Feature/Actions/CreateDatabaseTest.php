<?php

use App\Actions\Database\CreateDatabase;
use App\Actions\Database\StartDatabase;
use App\Actions\Shared\ResolveResourcePlacement;
use App\Enums\NewDatabaseTypes;
use App\Exceptions\PublicPortAlreadyUsedException;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function dbPlacement(): array
{
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $placement = ResolveResourcePlacement::run($team->id, $project->uuid, 'production', null, $server->uuid, null);

    return [$placement, $server->destinations()->first()];
}

it('creates a standalone postgres in the resolved placement without deploying', function () {
    Queue::fake();
    [$placement, $destination] = dbPlacement();

    $db = CreateDatabase::run($placement, NewDatabaseTypes::POSTGRESQL, ['name' => 'my-pg'], null, false);

    expect($db)->toBeInstanceOf(StandalonePostgresql::class)
        ->and($db->name)->toBe('my-pg')
        ->and($db->environment_id)->toBe($placement->environment->id)
        ->and($db->destination_id)->toBe($destination->id);
    StartDatabase::assertNotPushed();
});

it('dispatches StartDatabase when instant_deploy is true', function () {
    Queue::fake();
    [$placement] = dbPlacement();

    CreateDatabase::run($placement, NewDatabaseTypes::POSTGRESQL, [], null, true);

    StartDatabase::assertPushed();
});

it('applies the image argument to non-postgres engines', function () {
    Queue::fake();
    [$placement] = dbPlacement();

    $db = CreateDatabase::run($placement, NewDatabaseTypes::MYSQL, [], 'mysql:8.4', false);

    expect($db)->toBeInstanceOf(StandaloneMysql::class)->and($db->image)->toBe('mysql:8.4');
});

it('throws PublicPortAlreadyUsedException when the public port is taken on the server', function () {
    Queue::fake();
    [$placement] = dbPlacement();
    CreateDatabase::run($placement, NewDatabaseTypes::POSTGRESQL, ['is_public' => true, 'public_port' => 5433], null, false);

    CreateDatabase::run($placement, NewDatabaseTypes::MYSQL, ['is_public' => true, 'public_port' => 5433], null, false);
})->throws(PublicPortAlreadyUsedException::class);

<?php

use App\Livewire\Project\Service\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

it('runs the conversion only from the confirmation modal, not on the first click', function (string $model, string $action) {
    $part = $model::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'part',
        'service_id' => $this->service->id,
        'image' => 'postgres:17',
    ]);

    Livewire::test(Index::class, ['serviceApplication' => $part, 'embedded' => true])
        ->assertSeeHtml("submitAction: '{$action}'")
        ->assertDontSeeHtml("wire:click=\"{$action}\"");
})->with([
    'application to database' => [ServiceApplication::class, 'convertToDatabase'],
    'database to application' => [ServiceDatabase::class, 'convertToApplication'],
]);

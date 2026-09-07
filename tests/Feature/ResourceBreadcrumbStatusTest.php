<?php

use App\Livewire\Project\Database\Status as DatabaseStatus;
use App\Livewire\Project\Service\Status as ServiceStatus;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
});

it('refreshes the breadcrumb database status after it changes', function () {
    $database = StandalonePostgresql::create([
        'name' => 'postgres',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running:healthy',
    ]);

    $component = Livewire::test(DatabaseStatus::class, ['database' => $database])
        ->assertSee('Running');

    $database->update(['status' => 'exited']);

    $component
        ->call('refreshStatus')
        ->assertSee('Stopped')
        ->assertDontSee('Running');

    expect($component->instance()->getListeners())
        ->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked", 'refreshStatus');
});

it('refreshes the breadcrumb service status after a child status changes', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $application = ServiceApplication::forceCreate([
        'uuid' => (string) Str::uuid(),
        'service_id' => $service->id,
        'name' => 'web',
        'human_name' => 'Web',
        'image' => 'nginx:alpine',
        'status' => 'running:healthy',
    ]);

    $component = Livewire::test(ServiceStatus::class, ['service' => $service->fresh(['applications', 'databases'])])
        ->assertSee('Running');

    $application->update(['status' => 'exited']);

    $component
        ->call('refreshStatus')
        ->assertSee('Stopped')
        ->assertDontSee('Running');

    expect($component->instance()->getListeners())
        ->toHaveKey("echo-private:team.{$this->team->id},ServiceChecked", 'refreshStatus');
});

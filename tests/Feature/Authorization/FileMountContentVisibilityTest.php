<?php

use App\Livewire\Project\Service\FileStorage;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Process::fake();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->team->members()->attach($this->owner, ['role' => 'owner']);
    $this->team->members()->attach($this->member, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environmentId = $project->environments()->firstOrFail()->id;

    $application = Application::factory()->create([
        'environment_id' => $environmentId,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $service = Service::factory()->create([
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environmentId,
    ]);
    $serviceApplication = ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
    ]);

    $this->fileMounts = collect([$application, $serviceApplication])->map(fn ($resource) => LocalFileVolume::create([
        'fs_path' => '/data/app/.env',
        'mount_path' => '/app/.env',
        'content' => 'TOKEN=stored-file-mount-value',
        'is_directory' => false,
        'is_based_on_git' => false,
        'resource_id' => $resource->id,
        'resource_type' => $resource->getMorphClass(),
    ]));
});

it('keeps file mount content out of member state', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    foreach ($this->fileMounts as $fileMount) {
        $component = Livewire::test(FileStorage::class, ['fileStorage' => $fileMount])
            ->assertSet('content', null)
            ->assertSee('Hidden (only admins can view)');

        expect(json_encode($component->snapshot).$component->html())->not->toContain('stored-file-mount-value');
    }
});

it('keeps file mount content available to an owner', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    foreach ($this->fileMounts as $fileMount) {
        Livewire::test(FileStorage::class, ['fileStorage' => $fileMount])
            ->assertSet('content', 'TOKEN=stored-file-mount-value');
    }
});

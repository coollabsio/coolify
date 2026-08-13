<?php

use App\Livewire\Project\Edit;
use App\Livewire\Project\Index;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'avatar_storage_type' => 'local',
    ]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
});

it('stores a project icon using the instance image storage setting', function () {
    Storage::fake('local');

    $upload = UploadedFile::fake()->createWithContent('project.jpg', file_get_contents(base_path('tests/Fixtures/project-icon.jpg')));

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('icon', $upload)
        ->call('uploadIcon')
        ->assertHasNoErrors();

    $this->project->refresh();

    expect($this->project->icon_path)
        ->toBe("project-icons/{$this->project->uuid}/icon.jpg")
        ->and($this->project->icon_storage_type)->toBe('local')
        ->and($this->project->icon_s3_storage_id)->toBeNull();

    Storage::disk('local')->assertExists($this->project->icon_path);
});

it('serves a project icon only to a member of its team', function () {
    Storage::fake('local');
    $this->project->forceFill([
        'icon_path' => "project-icons/{$this->project->uuid}/icon.jpg",
        'icon_storage_type' => 'local',
    ])->save();
    Storage::disk('local')->put($this->project->icon_path, 'icon-content');

    $this->withoutMiddleware()->get(route('project.icon', ['project_uuid' => $this->project->uuid]))
        ->assertSuccessful()
        ->assertHeader('content-type', 'image/jpeg');

    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);
    $this->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    $this->get(route('project.icon', ['project_uuid' => $this->project->uuid]))
        ->assertNotFound();
});

it('removes a project icon', function () {
    Storage::fake('local');
    $path = "project-icons/{$this->project->uuid}/icon.jpg";
    $this->project->forceFill([
        'icon_path' => $path,
        'icon_storage_type' => 'local',
    ])->save();
    Storage::disk('local')->put($path, 'icon-content');

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->call('removeIcon')
        ->assertHasNoErrors();

    expect($this->project->refresh()->icon_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('exposes the icon URL on the projects index', function () {
    $this->project->forceFill([
        'icon_path' => "project-icons/{$this->project->uuid}/icon.jpg",
        'icon_storage_type' => 'local',
    ])->save();

    Livewire::test(Index::class)
        ->assertViewHas('projectsJs', fn (array $projects): bool => $projects[0]['iconUrl'] === route('project.icon', [
            'project_uuid' => $this->project->uuid,
            'v' => $this->project->updated_at->timestamp,
        ]));
});

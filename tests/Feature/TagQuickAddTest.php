<?php

use App\Livewire\Project\Shared\Tags;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

test('quick add passes only the tag id to the action', function () {
    $name = "o'reilly";
    Livewire::test(Tags::class, ['resource' => $this->application])
        ->set('newTags', $name)
        ->call('submit')
        ->assertNotDispatched('error');

    $tag = Tag::ownedByCurrentTeam()->where('name', $name)->firstOrFail();
    $otherApplication = Application::factory()->create([
        'environment_id' => $this->application->environment_id,
        'destination_id' => $this->application->destination_id,
        'destination_type' => $this->application->destination_type,
    ]);

    $html = Livewire::test(Tags::class, ['resource' => $otherApplication])->html();
    expect($html)->toContain("wire:click=\"addTag('{$tag->id}')\"")
        ->not->toMatch('/wire:click="[^"]*reilly/');
});

test('addTag accepts only a tag owned by the current team', function () {
    $ownTag = Tag::create(['name' => 'own-tag', 'team_id' => $this->team->id]);
    $otherTag = Tag::create(['name' => 'other-tag', 'team_id' => Team::factory()->create()->id]);

    Livewire::test(Tags::class, ['resource' => $this->application])
        ->call('addTag', (string) $otherTag->id);
    expect($this->application->tags()->whereKey($otherTag->id)->exists())->toBeFalse();

    Livewire::test(Tags::class, ['resource' => $this->application])
        ->call('addTag', (string) $ownTag->id);
    expect($this->application->tags()->whereKey($ownTag->id)->exists())->toBeTrue();
});

test('member cannot create a tag through resource settings', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Tags::class, ['resource' => $this->application])
        ->set('newTags', 'member-tag')
        ->call('submit')
        ->assertDispatched('error');
    expect(Tag::where('name', 'member-tag')->exists())->toBeFalse();
});

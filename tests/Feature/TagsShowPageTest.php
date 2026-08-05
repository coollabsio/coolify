<?php

use App\Livewire\Tags\Show;
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
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('shows an empty state when there are no tags', function () {
    Livewire::test(Show::class)
        ->assertSee('Tags')
        ->assertSee('No tags yet')
        ->assertSee('Open a resource and add a tag to start grouping related deployments.');
});

it('shows a card grid overview when tags exist and none is selected', function () {
    Tag::create(['name' => 'hello', 'team_id' => $this->team->id]);
    Tag::create(['name' => 'production', 'team_id' => $this->team->id]);

    Livewire::test(Show::class)
        ->assertSee('Tags')
        ->assertSee('2 tags for bulk deploys and grouping')
        ->assertSee('hello')
        ->assertSee('production')
        ->assertSee('Search tags')
        ->assertSee('Sort')
        ->assertSee('Table view', false)
        ->assertSee('Grid view', false)
        ->assertSee('tagsIndex', false)
        ->assertSee('tags-view', false)
        ->assertDontSee('Switch tag')
        ->assertDontSee('Deploy webhook URL');
});

it('includes filterable tags payload for the overview', function () {
    Tag::create(['name' => 'hello', 'team_id' => $this->team->id]);
    Tag::create(['name' => 'production', 'team_id' => $this->team->id]);

    $html = Livewire::test(Show::class)->html();

    expect($html)
        ->toContain('hello')
        ->toContain('production')
        ->toContain('resourceCount')
        ->toContain('applicationsCount')
        ->toContain('servicesCount')
        ->toContain('setViewMode')
        ->toContain('filteredTags');
});

it('shows the tag detail layout with stats when a tag is selected', function () {
    $tag = Tag::create(['name' => 'hello', 'team_id' => $this->team->id]);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'tagged-app',
    ]);
    $application->tags()->attach($tag->id);

    Livewire::test(Show::class, ['tagName' => 'hello'])
        ->assertSee('Tags')
        ->assertDontSee('Switch tag')
        ->assertSee('Resources')
        ->assertSee('Applications')
        ->assertSee('Active deployments')
        ->assertSee('Deploy webhook URL')
        ->assertSee('Redeploy all')
        ->assertSee('tagged-app')
        ->assertDontSee('No resources use this tag');

    $breadcrumbs = file_get_contents(resource_path('views/components/top-breadcrumb.blade.php'));
    expect($breadcrumbs)
        ->toContain('title="Tags"')
        ->toContain("'label' => 'All tags'")
        ->toContain("route('tags.show', ['tagName' => \$tag->name])");
});

it('shows empty resource and deployment states for an unused tag', function () {
    Tag::create(['name' => 'orphan', 'team_id' => $this->team->id]);

    Livewire::test(Show::class, ['tagName' => 'orphan'])
        ->assertSee('No resources use this tag')
        ->assertSee('No active deployments')
        ->assertSee('Using this tag')
        ->assertSee('Queued or running');
});

it('adds spacing around the active deployments empty state', function () {
    $view = file_get_contents(resource_path('views/livewire/tags/show.blade.php'));

    expect($view)
        ->toMatch('/<div class="p-3">\s*<x-empty title="No active deployments"/');
});

it('redirects to the overview when the requested tag does not exist', function () {
    Tag::create(['name' => 'hello', 'team_id' => $this->team->id]);

    Livewire::test(Show::class, ['tagName' => 'missing-tag'])
        ->assertRedirect(route('tags.show'));
});

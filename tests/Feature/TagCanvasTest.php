<?php

use App\Livewire\Project\Shared\Tags as ResourceTags;
use App\Livewire\Tags\Canvas as TagsCanvas;
use App\Livewire\Tags\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.driver' => 'file',
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    Server::flushIdentityMap();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->admin = User::factory()->create();
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id, 'name' => 'staging']);
    $this->storefront = Application::factory()->create([
        'name' => 'storefront', 'build_pack' => 'dockerimage', 'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->tag = Tag::create(['name' => 'production', 'team_id' => $this->team->id]);
});

function tagCanvasAs(object $test, User $user, Team $team): object
{
    return $test->actingAs($user)->withSession(['currentTeam' => $team])
        ->withHeader('X-Canvas-Team', (string) $team->id);
}

test('the tags canvas shows every tag, also without resources, and which resources have them', function () {
    $database = StandalonePostgresql::create([
        'name' => 'orders-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id, 'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->storefront->tags()->attach($this->tag->id);
    $database->tags()->attach($this->tag->id);
    $empty = Tag::create(['name' => 'unused', 'team_id' => $this->team->id]);

    $response = tagCanvasAs($this, $this->admin, $this->team)
        ->getJson('/canvas/api/tags/canvas')
        ->assertOk()
        ->assertJsonPath('layout', 'connect')
        ->assertJsonPath('can_edit', true);

    expect(collect($response->json('nodes'))->mapWithKeys(fn ($node) => [$node['id'] => $node['type']])->all())->toBe([
        'tag:'.$this->tag->uuid => 'tag',
        'tag:'.$empty->uuid => 'tag',
        $database->uuid => 'database',
        $this->storefront->uuid => 'application',
    ])->and(collect($response->json('links'))->pluck('id')->sort()->values()->all())->toBe(collect([
        'tag:'.$this->tag->uuid.'|'.$database->uuid,
        'tag:'.$this->tag->uuid.'|'.$this->storefront->uuid,
    ])->sort()->values()->all());
});

test('an admin tags and untags a resource from the canvas, and the tag stays', function () {
    $source = 'tag:'.$this->tag->uuid;

    tagCanvasAs($this, $this->admin, $this->team)
        ->postJson('/canvas/api/tags/canvas/links', ['source' => $source, 'target' => $this->storefront->uuid])
        ->assertCreated()
        ->assertJsonPath('id', $source.'|'.$this->storefront->uuid);
    expect($this->storefront->tags()->pluck('name')->all())->toBe(['production']);

    tagCanvasAs($this, $this->admin, $this->team)
        ->postJson('/canvas/api/tags/canvas/links', ['source' => $source, 'target' => $this->storefront->uuid])
        ->assertStatus(422)
        ->assertJsonPath('message', 'storefront already has the production tag.');

    tagCanvasAs($this, $this->admin, $this->team)
        ->deleteJson('/canvas/api/tags/canvas/links/'.rawurlencode($source.'|'.$this->storefront->uuid))
        ->assertOk();
    expect($this->storefront->tags()->count())->toBe(0)
        ->and(Tag::find($this->tag->id))->not->toBeNull();
});

test('a member sees the tags canvas but cannot tag resources', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    tagCanvasAs($this, $member, $this->team)->getJson('/canvas/api/tags/canvas')
        ->assertOk()
        ->assertJsonPath('can_edit', false);
    tagCanvasAs($this, $member, $this->team)
        ->postJson('/canvas/api/tags/canvas/links', ['source' => 'tag:'.$this->tag->uuid, 'target' => $this->storefront->uuid])
        ->assertForbidden();

    expect($this->storefront->tags()->count())->toBe(0);
});

test('tags and resources of another team cannot be connected', function () {
    $otherTeam = Team::factory()->create(['show_boarding' => false]);
    $otherTeam->members()->attach($this->admin->id, ['role' => 'owner']);
    $otherTag = Tag::create(['name' => 'foreign', 'team_id' => $otherTeam->id]);

    // The session is switched to the other team after the canvas was opened.
    tagCanvasAs($this, $this->admin, $otherTeam)
        ->postJson('/canvas/api/tags/canvas/links', ['source' => 'tag:'.$otherTag->uuid, 'target' => $this->storefront->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('target');
    tagCanvasAs($this, $this->admin, $this->team)
        ->postJson('/canvas/api/tags/canvas/links', ['source' => 'tag:'.$otherTag->uuid, 'target' => $this->storefront->uuid])
        ->assertStatus(422)
        ->assertJsonValidationErrors('source');

    expect($this->storefront->tags()->count())->toBe(0);
});

test('the tags page creates a tag without resources and deletes a tag', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class)
        ->set('newTagName', 'Staging')
        ->call('createTag')
        ->assertHasNoErrors()
        ->assertRedirect(route('tags.show', ['tagName' => 'staging']));
    expect(Tag::query()->where('team_id', $this->team->id)->where('name', 'staging')->exists())->toBeTrue();

    Livewire::test(Show::class)->set('newTagName', 'production')->call('createTag')->assertHasErrors('newTagName');
    Livewire::test(Show::class)->set('newTagName', 'two words')->call('createTag')->assertHasErrors('newTagName');

    $this->storefront->tags()->attach($this->tag->id);
    Livewire::test(Show::class, ['tagName' => 'production'])
        ->call('deleteTag')
        ->assertRedirect(route('tags.show'));
    expect(Tag::find($this->tag->id))->toBeNull()
        ->and($this->storefront->tags()->count())->toBe(0);
});

test('the tags canvas creates a tag, and a member cannot', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(TagsCanvas::class)
        ->assertSee('New tag')
        ->set('newTagName', 'Staging')
        ->call('createTag')
        ->assertHasNoErrors()
        ->assertRedirect(route('tags.show', ['tagName' => 'staging']));

    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(TagsCanvas::class)
        ->assertDontSee('New tag')
        ->set('newTagName', 'qa')
        ->call('createTag');

    expect(Tag::query()->where('team_id', $this->team->id)->orderBy('name')->pluck('name')->all())->toBe(['production', 'staging']);
});

test('a member cannot create or delete tags on the tags page', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class)->set('newTagName', 'staging')->call('createTag');
    Livewire::test(Show::class, ['tagName' => 'production'])->call('deleteTag');

    expect(Tag::query()->where('team_id', $this->team->id)->pluck('name')->all())->toBe(['production']);
});

test('removing the last resource from a tag on the resource page keeps the tag', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $this->storefront->tags()->attach($this->tag->id);

    Livewire::test(ResourceTags::class, ['resource' => $this->storefront])
        ->call('deleteTag', (string) $this->tag->id);

    expect($this->storefront->tags()->count())->toBe(0)
        ->and(Tag::find($this->tag->id))->not->toBeNull();
});

test('the tags canvas page opens a tag from its connect link', function () {
    tagCanvasAs($this, $this->admin, $this->team)->get(route('tags.canvas'))
        ->assertOk()
        ->assertSee('data-mode="tags"', false);
    tagCanvasAs($this, $this->admin, $this->team)->get(route('tags.show', ['tagName' => 'production']))
        ->assertOk()
        ->assertSee(route('tags.canvas').'?select=tag%3A'.$this->tag->uuid, false);
});

test('the tags page reopens the canvas when the user left it on the canvas view', function () {
    tagCanvasAs($this, $this->admin, $this->team)
        ->get(route('tags.canvas'))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_tags_view", 'canvas');

    tagCanvasAs($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_tags_view", 'canvas')
        ->get(route('tags.show'))
        ->assertRedirect(route('tags.canvas'));

    tagCanvasAs($this, $this->admin, $this->team)
        ->withCookie("coolify_{$this->admin->id}_tags_view", 'canvas')
        ->get(route('tags.show', ['view' => 'grid']))
        ->assertOk()
        ->assertCookie("coolify_{$this->admin->id}_tags_view", 'grid');
});

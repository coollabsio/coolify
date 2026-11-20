<?php

use App\Livewire\Dashboard;
use App\Livewire\GlobalSearch;
use App\Livewire\Project\Index as ProjectIndex;
use App\Livewire\Team\Member\Access;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
        'app.maintenance.store' => 'array',
    ]);
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    Server::flushIdentityMap();
    Cache::flush();

    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'name' => 'web-server']);
    $destination = $this->server->standaloneDockers()->firstOrFail();

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $shopProduction = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'blog']);
    $blogStaging = Environment::factory()->create(['project_id' => $this->blog->id, 'name' => 'staging']);

    $this->shopApi = Application::factory()->create([
        'name' => 'shop-api',
        'environment_id' => $shopProduction->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->blogWeb = Application::factory()->create([
        'name' => 'blog-web',
        'environment_id' => $blogStaging->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    $this->owner = memberGrantsUser($this->team, 'owner', null);
});

function memberGrantsUser(Team $team, string $role, ?array $access): User
{
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user;
}

function memberGrantsActAs(User $user, Team $team): void
{
    test()->actingAs($user);
    session(['currentTeam' => $team]);
}

function memberGrantsStoredAccess(Team $team, User $user): mixed
{
    $stored = DB::table('team_user')->where('team_id', $team->id)->where('user_id', $user->id)->value('access');

    return $stored === null ? null : json_decode($stored, true);
}

it('lets an owner save grants for a member through the access editor', function () {
    $member = memberGrantsUser($this->team, 'member', null);
    memberGrantsActAs($this->owner, $this->team);

    Livewire::test(Access::class)->call('openFor', $member->id)
        ->set('grants', [
            ['key' => 'a', 'targets' => ['project:'.$this->shop->uuid], 'levels' => ['Write' => true, 'Deploy' => true]],
            ['key' => 'b', 'targets' => ['resource:'.$this->blogWeb->uuid], 'levels' => ['Read' => true]],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(memberGrantsStoredAccess($this->team, $member))->toBe([
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['deploy', 'write']],
        ['scope' => 'resource', 'uuid' => $this->blogWeb->uuid, 'levels' => ['read']],
    ]);
});

it('saves one grant for each target of a scope and groups them again on open', function () {
    $member = memberGrantsUser($this->team, 'member', null);
    memberGrantsActAs($this->owner, $this->team);

    Livewire::test(Access::class)->call('openFor', $member->id)
        ->set('grants.0.targets', ['project:'.$this->shop->uuid, 'resource:'.$this->blogWeb->uuid])
        ->call('save')
        ->assertHasNoErrors();

    expect(memberGrantsStoredAccess($this->team, $member))->toBe([
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
        ['scope' => 'resource', 'uuid' => $this->blogWeb->uuid, 'levels' => ['read']],
    ]);

    Livewire::test(Access::class)->call('openFor', $member->id)
        ->assertCount('grants', 1)
        ->assertSet('grants.0.targets', ['project:'.$this->shop->uuid, 'resource:'.$this->blogWeb->uuid]);
});

it('stores an empty list when the owner removes every grant', function () {
    $member = memberGrantsUser($this->team, 'member', null);
    memberGrantsActAs($this->owner, $this->team);

    Livewire::test(Access::class)->call('openFor', $member->id)
        ->set('grants', [])
        ->call('save')
        ->assertHasNoErrors();

    expect(memberGrantsStoredAccess($this->team, $member))->toBe([])
        ->and(Access::summary('[]'))->toBe('No access')
        ->and(Access::summary(null))->toBe('Team-wide read (default)');
});

it('does not let a member open the editor or save grants', function () {
    $member = memberGrantsUser($this->team, 'member', null);
    $target = memberGrantsUser($this->team, 'member', null);

    memberGrantsActAs($member, $this->team);
    Livewire::test(Access::class)->assertForbidden();

    memberGrantsActAs($this->owner, $this->team);
    $editor = Livewire::test(Access::class)
        ->call('openFor', $target->id)
        ->set('grants', [['key' => 'a', 'targets' => ['team'], 'levels' => ['Root' => true]]]);

    memberGrantsActAs($member->fresh(), $this->team);
    $editor->call('save')->assertForbidden();

    expect(memberGrantsStoredAccess($this->team, $target))->toBeNull();
});

it('rejects a scope from another team', function () {
    $member = memberGrantsUser($this->team, 'member', null);
    $otherTeam = Team::factory()->create();
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    memberGrantsActAs($this->owner, $this->team);

    Livewire::test(Access::class)->call('openFor', $member->id)
        ->set('grants.0.targets', ['team', 'project:'.$foreignProject->uuid])
        ->call('save')
        ->assertHasErrors(['grants.0.targets']);

    expect(memberGrantsStoredAccess($this->team, $member))->toBeNull();
});

it('blocks team-level pages for a member with only a project grant', function (string $path) {
    $member = memberGrantsUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
    ]);

    $this->actingAs($member)->withSession(['currentTeam' => $this->team])->get($path)->assertForbidden();
})->with([
    '/servers',
    '/destinations',
    '/security/private-key',
    '/sources',
    '/storages',
    '/notifications/email',
    '/team',
    '/team/members',
    '/shared-variables/team',
    '/tags',
    '/analytics',
    '/settings',
]);

it('keeps team-level pages for legacy members and owners', function () {
    $legacy = memberGrantsUser($this->team, 'member', null);

    $this->actingAs($legacy)->withSession(['currentTeam' => $this->team])->get('/servers')->assertOk();
    $this->actingAs($this->owner)->withSession(['currentTeam' => $this->team])->get('/servers')->assertOk();
});

it('keeps the own API tokens page for a scoped member', function () {
    $member = memberGrantsUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
    ]);

    $this->actingAs($member)->withSession(['currentTeam' => $this->team])->get('/security/api-tokens')->assertOk();
});

it('lists only granted projects and hides other project pages', function () {
    $member = memberGrantsUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
    ]);
    memberGrantsActAs($member, $this->team);

    expect(Livewire::test(ProjectIndex::class)->get('projects')->pluck('uuid')->all())->toBe([$this->shop->uuid]);

    $this->get('/project/'.$this->shop->uuid)->assertOk();
    $this->get('/project/'.$this->blog->uuid)->assertNotFound();
});

it('keeps hidden resources out of global search for a scoped member, and full results for an admin after', function () {
    $member = memberGrantsUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
    ]);

    memberGrantsActAs($member, $this->team);
    $memberItems = collect(Livewire::test(GlobalSearch::class)->call('openSearchModal')->get('allSearchableItems'));

    expect($memberItems->whereIn('type', ['application', 'project', 'server'])->pluck('name')->sort()->values()->all())
        ->toBe(['shop', 'shop-api'])
        ->and($memberItems->where('type', 'navigation')->pluck('link')->all())->not->toContain(route('server.index'), route('team.member.index'));

    memberGrantsActAs($this->owner, $this->team);
    $ownerItems = collect(Livewire::test(GlobalSearch::class)->call('openSearchModal')->get('allSearchableItems'));

    expect($ownerItems->whereIn('type', ['application', 'project', 'server'])->pluck('name')->sort()->values()->all())
        ->toBe(['blog', 'blog-web', 'shop', 'shop-api', 'web-server']);
});

it('shows no servers on the dashboard for a scoped member', function () {
    $member = memberGrantsUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
    ]);
    memberGrantsActAs($member, $this->team);

    $dashboard = Livewire::test(Dashboard::class);

    expect($dashboard->get('servers')->all())->toBe([])
        ->and($dashboard->get('projects')->pluck('uuid')->all())->toBe([$this->shop->uuid]);

    expect(fn () => $dashboard->set('showsTeamSections', true))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('renders the members page and the editor with every scope of the team for an owner', function () {
    $member = memberGrantsUser($this->team, 'member', [
        ['scope' => 'project', 'uuid' => $this->shop->uuid, 'levels' => ['read']],
        ['scope' => 'project', 'uuid' => $this->blog->uuid, 'levels' => ['read']],
    ]);
    memberGrantsActAs($this->owner, $this->team);

    $this->get('/team/members')->assertOk()->assertSee('2 projects');

    $options = Livewire::test(Access::class)->call('openFor', $member->id)->assertOk()->instance()->scopeOptions();

    expect(collect($options)->reject(fn (array $option) => $option['header'] ?? false)->pluck('label')->all())->toBe([
        'Whole team', 'blog', 'shop',
        'blog / production', 'blog / staging', 'shop / prod', 'shop / production',
        'blog / staging / blog-web', 'shop / prod / shop-api',
    ]);
});

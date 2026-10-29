<?php

use App\Livewire\Project\Resource\Create as ResourceCreate;
use App\Livewire\Project\Shared\EnvironmentVariable\Show as EnvironmentVariableShow;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();

    $this->team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $placement = [
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ];

    $this->shop = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'shop']);
    $this->shopProduction = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'prod']);
    $this->shopStaging = Environment::factory()->create(['project_id' => $this->shop->id, 'name' => 'staging']);
    $this->blog = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'blog']);
    $blogProduction = Environment::factory()->create(['project_id' => $this->blog->id, 'name' => 'prod']);

    $this->application = Application::factory()->create(['environment_id' => $this->shopProduction->id, ...$placement]);
    $this->database = StandalonePostgresql::create([
        'name' => 'orders-db', 'postgres_user' => 'postgres', 'postgres_password' => 'password', 'postgres_db' => 'db',
        'image' => 'postgres:17', 'environment_id' => $this->shopProduction->id, ...$placement,
    ]);
    $this->service = Service::factory()->create([
        'environment_id' => $this->shopProduction->id,
        'server_id' => $server->id,
        ...$placement,
    ]);
    $this->secret = EnvironmentVariable::create([
        'key' => 'API_KEY',
        'value' => 'shop-secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
        'is_preview' => false,
    ]);
    $this->blogApplication = Application::factory()->create(['environment_id' => $blogProduction->id, ...$placement]);
});

function accessPoliciesMember(Team $team, ?array $access, string $role = 'member'): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);
    $user = $user->fresh();
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return $user;
}

function accessPoliciesProjectGrant(Project $project, array $levels): array
{
    return [['scope' => 'project', 'uuid' => $project->uuid, 'levels' => $levels]];
}

/**
 * @return array<string, bool>
 */
function accessPoliciesAbilities(User $user, object $test): array
{
    $gate = Gate::forUser($user);

    return [
        'application.view' => $gate->allows('view', $test->application),
        'application.deploy' => $gate->allows('deploy', $test->application),
        'application.update' => $gate->allows('update', $test->application),
        'application.delete' => $gate->allows('delete', $test->application),
        'database.view' => $gate->allows('view', $test->database),
        'database.update' => $gate->allows('update', $test->database),
        'database.delete' => $gate->allows('delete', $test->database),
        'service.view' => $gate->allows('view', $test->service),
        'service.deploy' => $gate->allows('deploy', $test->service),
        'service.update' => $gate->allows('update', $test->service),
        'service.delete' => $gate->allows('delete', $test->service),
        'secret.view' => $gate->allows('view', $test->secret),
        'secret.viewSensitive' => $gate->allows('viewSensitive', $test->secret),
        'secret.update' => $gate->allows('update', $test->secret),
    ];
}

/**
 * @param  list<string>  $allowed
 * @return array<string, bool>
 */
function accessPoliciesExpected(array $allowed): array
{
    $keys = [
        'application.view', 'application.deploy', 'application.update', 'application.delete',
        'database.view', 'database.update', 'database.delete',
        'service.view', 'service.deploy', 'service.update', 'service.delete',
        'secret.view', 'secret.viewSensitive', 'secret.update',
    ];

    return array_combine($keys, array_map(fn (string $key) => in_array($key, $allowed, true), $keys));
}

const ACCESS_POLICIES_VIEW = ['application.view', 'database.view', 'service.view', 'secret.view'];

it('lets a member with a deploy grant deploy but not change or delete', function () {
    $member = accessPoliciesMember($this->team, accessPoliciesProjectGrant($this->shop, ['deploy']));

    expect(accessPoliciesAbilities($member, $this))->toBe(accessPoliciesExpected([
        ...ACCESS_POLICIES_VIEW, 'application.deploy', 'service.deploy',
    ]))->and(Gate::forUser($member)->allows('view', $this->blogApplication))->toBeFalse();
});

it('lets a member with a write grant change resources and read secrets but not delete', function () {
    $member = accessPoliciesMember($this->team, accessPoliciesProjectGrant($this->shop, ['write']));

    expect(accessPoliciesAbilities($member, $this))->toBe(accessPoliciesExpected([
        ...ACCESS_POLICIES_VIEW, 'application.deploy', 'application.update', 'database.update',
        'service.deploy', 'service.update', 'secret.viewSensitive', 'secret.update',
    ]));
});

it('lets a member with a delete grant delete but not change', function () {
    $member = accessPoliciesMember($this->team, accessPoliciesProjectGrant($this->shop, ['delete']));

    expect(accessPoliciesAbilities($member, $this))->toBe(accessPoliciesExpected([
        ...ACCESS_POLICIES_VIEW, 'application.delete', 'database.delete', 'service.delete',
    ]));
});

it('applies a resource grant only to that resource', function () {
    $member = accessPoliciesMember($this->team, [
        ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['write']],
    ]);

    expect(accessPoliciesAbilities($member, $this))->toBe(accessPoliciesExpected([
        'application.view', 'application.deploy', 'application.update',
        'secret.view', 'secret.viewSensitive', 'secret.update',
    ]));
});

it('keeps a member without stored grants read-only', function () {
    $member = accessPoliciesMember($this->team, null);

    expect(accessPoliciesAbilities($member, $this))->toBe(accessPoliciesExpected(ACCESS_POLICIES_VIEW))
        ->and(Gate::forUser($member)->allows('view', $this->blogApplication))->toBeTrue()
        ->and(Gate::forUser($member)->allows('createAnyResource'))->toBeFalse()
        ->and(Gate::forUser($member)->allows('create', Project::class))->toBeFalse();
});

it('gives admins full access', function () {
    $admin = accessPoliciesMember($this->team, null, 'admin');

    expect(accessPoliciesAbilities($admin, $this))->toBe(accessPoliciesExpected([
        ...ACCESS_POLICIES_VIEW, 'application.deploy', 'application.update', 'application.delete',
        'database.update', 'database.delete', 'service.deploy', 'service.update', 'service.delete',
        'secret.viewSensitive', 'secret.update',
    ]))->and(Gate::forUser($admin)->allows('createAnyResource'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [Application::class, $this->shopStaging]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', Project::class))->toBeTrue();
});

it('uses the grants of the resource team after the session switches to another team', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member', 'access' => json_encode(accessPoliciesProjectGrant($this->shop, ['write']))]);
    $ownTeam = Team::factory()->create();
    $member->teams()->attach($ownTeam, ['role' => 'owner']);
    $member = $member->fresh();
    $this->actingAs($member);
    session(['currentTeam' => $ownTeam]);

    $gate = Gate::forUser($member);

    expect($gate->allows('update', $this->application))->toBeTrue()
        ->and($gate->allows('viewSensitive', $this->secret))->toBeTrue()
        ->and($gate->allows('delete', $this->application))->toBeFalse()
        ->and($gate->allows('view', $this->blogApplication))->toBeFalse();
});

it('lets a member create resources only in environments they can write to', function () {
    $member = accessPoliciesMember($this->team, [
        ['scope' => 'environment', 'uuid' => $this->shopStaging->uuid, 'levels' => ['write']],
    ]);
    $gate = Gate::forUser($member);

    expect($gate->allows('create', [Application::class, $this->shopStaging]))->toBeTrue()
        ->and($gate->allows('create', [Service::class, $this->shopStaging]))->toBeTrue()
        ->and($gate->allows('create', [StandalonePostgresql::class, $this->shopStaging]))->toBeTrue()
        ->and($gate->allows('createAnyResource', [$this->shopStaging]))->toBeTrue()
        ->and($gate->allows('create', [Application::class, $this->shopProduction]))->toBeFalse()
        ->and($gate->allows('create', Application::class))->toBeFalse()
        ->and($gate->allows('create', [Environment::class, $this->shop]))->toBeFalse()
        ->and($gate->allows('create', Project::class))->toBeFalse();
});

it('shows secret values in the variable editor only with read-sensitive access', function (array $levels, ?string $expectedValue) {
    accessPoliciesMember($this->team, accessPoliciesProjectGrant($this->shop, $levels));

    $component = Livewire::test(EnvironmentVariableShow::class, ['env' => $this->secret, 'type' => 'application']);

    if ($expectedValue === null) {
        expect($component->get('isValueHidden'))->toBeTrue()
            ->and($component->get('value'))->toBeNull();
        $component->call('loadValues')->assertForbidden();

        return;
    }

    $component->call('loadValues');
    expect($component->get('value'))->toBe($expectedValue)
        ->and($component->get('isValueHidden'))->toBeFalse();
})->with([
    'read' => [['read'], null],
    'deploy' => [['deploy'], null],
    'read:sensitive' => [['read:sensitive'], 'shop-secret-value'],
    'write' => [['write'], 'shop-secret-value'],
]);

it('finds domain conflicts with resources the member cannot see without naming them', function () {
    $this->blogApplication->update(['fqdn' => 'https://blog.example.com']);
    $this->application->update(['fqdn' => 'https://blog.example.com']);
    accessPoliciesMember($this->team, accessPoliciesProjectGrant($this->shop, ['write']));

    $result = checkDomainUsage(resource: $this->application->fresh());

    expect($result['hasConflicts'])->toBeTrue()
        ->and($result['conflicts'][0]['message'])->toBe('Domain https://blog.example.com is already in use by another resource in this team')
        ->and($result['conflicts'][0]['resource_name'])->toBe('another resource');
});

function accessPoliciesBindEnvironmentRoute(Environment $environment): void
{
    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($environment): void {
        $event->route->setParameter('project_uuid', $environment->project->uuid);
        $event->route->setParameter('environment_uuid', $environment->uuid);
    });
}

it('opens the new resource page only for environments the member can write to', function () {
    accessPoliciesMember($this->team, [
        ...accessPoliciesProjectGrant($this->shop, ['read']),
        ['scope' => 'environment', 'uuid' => $this->shopStaging->uuid, 'levels' => ['write']],
    ]);

    accessPoliciesBindEnvironmentRoute($this->shopStaging);
    Livewire::test(ResourceCreate::class)->assertOk();

    Event::forget(RouteMatched::class);
    accessPoliciesBindEnvironmentRoute($this->shopProduction);
    Livewire::test(ResourceCreate::class)->assertForbidden();
});

afterEach(function () {
    Event::forget(RouteMatched::class);
});

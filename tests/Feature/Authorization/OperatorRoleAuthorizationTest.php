<?php

use App\Livewire\Boarding\Index as BoardingIndex;
use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Team\InviteLink;
use App\Livewire\Team\Member;
use App\Livewire\Terminal\Index as TerminalIndex;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.store' => 'array',
        'cache.default' => 'array',
    ]);

    $this->withoutVite();

    InstanceSettings::query()->forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->operator = User::factory()->create();
    $this->operator->teams()->attach($this->team, ['role' => 'operator']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    session(['currentTeam' => $this->team]);
});

test('operator can create and manage resources while member stays read-only', function () {
    expect($this->operator->can('createAnyResource'))->toBeTrue()
        ->and($this->operator->can('create', Application::class))->toBeTrue()
        ->and($this->operator->can('create', Service::class))->toBeTrue()
        ->and($this->operator->can('create', StandalonePostgresql::class))->toBeTrue()
        ->and($this->operator->can('create', Project::class))->toBeTrue()
        ->and($this->operator->can('create', SharedEnvironmentVariable::class))->toBeTrue();

    expect($this->member->can('createAnyResource'))->toBeFalse()
        ->and($this->member->can('create', Application::class))->toBeFalse()
        ->and($this->member->can('create', Project::class))->toBeFalse();
});

test('operator can update team-scoped resources', function () {
    $project = Project::create([
        'name' => 'Operator Project',
        'team_id' => $this->team->id,
    ]);

    expect($this->operator->can('update', $project))->toBeTrue()
        ->and($this->operator->can('delete', $project))->toBeTrue()
        ->and($this->member->can('update', $project))->toBeFalse();
});

test('operator cannot access the terminal gate', function () {
    expect($this->operator->can('canAccessTerminal'))->toBeFalse()
        ->and($this->member->can('canAccessTerminal'))->toBeFalse()
        ->and($this->admin->can('canAccessTerminal'))->toBeTrue()
        ->and($this->owner->can('canAccessTerminal'))->toBeTrue();
});

test('operator cannot touch credential or persistent-access surfaces', function () {
    expect($this->operator->can('create', GithubApp::class))->toBeFalse()
        ->and($this->operator->can('create', GitlabApp::class))->toBeFalse()
        ->and($this->operator->can('create', PrivateKey::class))->toBeFalse()
        ->and($this->operator->can('create', Server::class))->toBeFalse()
        ->and($this->operator->can('create', S3Storage::class))->toBeFalse()
        ->and($this->operator->can('update', $this->team))->toBeFalse()
        ->and($this->operator->can('manageMembers', $this->team))->toBeFalse()
        ->and($this->operator->can('manageInvitations', $this->team))->toBeFalse()
        ->and($this->operator->can('viewAdmin', $this->team))->toBeFalse()
        ->and($this->operator->can('delete', $this->team))->toBeFalse();
});

test('operator cannot hold elevated api token permissions', function () {
    expect($this->operator->can('useRootPermissions', PersonalAccessToken::class))->toBeFalse()
        ->and($this->operator->can('useWritePermissions', PersonalAccessToken::class))->toBeFalse()
        ->and($this->operator->can('useDeployPermissions', PersonalAccessToken::class))->toBeFalse()
        ->and($this->operator->can('useSensitivePermissions', PersonalAccessToken::class))->toBeFalse();
});

test('operator can use a read-only api token', function () {
    $read = $this->operator->createToken('operator-read', ['read']);

    $this->withHeaders(['Authorization' => 'Bearer '.$read->plainTextToken])
        ->getJson('/api/v1/version')
        ->assertSuccessful();
});

test('operator cannot use an elevated api token', function () {
    $write = $this->operator->createToken('operator-write', ['write']);

    $this->withHeaders(['Authorization' => 'Bearer '.$write->plainTextToken])
        ->getJson('/api/v1/version')
        ->assertForbidden();
});

test('operator cannot update team settings through the model layer', function () {
    $this->actingAs($this->operator);

    expect(fn () => $this->team->update(['name' => 'Renamed by operator']))
        ->toThrow(Exception::class, 'You are not allowed to update this team.');
});

test('operator can dismiss onboarding without hitting the team update guard', function () {
    $this->actingAs($this->operator);

    $showBoardingBefore = $this->team->fresh()->show_boarding;

    Livewire::test(BoardingIndex::class)
        ->call('skipBoarding')
        ->assertRedirect(route('dashboard'));

    expect($this->team->fresh()->show_boarding)->toBe($showBoardingBefore);
});

test('operator cannot mount terminal components', function () {
    $this->actingAs($this->operator);

    Livewire::test(TerminalIndex::class)->assertForbidden();
    Livewire::test(ExecuteContainerCommand::class)->assertForbidden();
});

test('operator cannot invoke terminal container loading directly', function () {
    $this->actingAs($this->admin);
    $component = Livewire::test(TerminalIndex::class);

    $this->actingAs($this->operator);
    $component->call('loadContainers')->assertForbidden();
});

test('admin and owner can invite an operator', function () {
    $this->actingAs($this->admin);

    Livewire::test(InviteLink::class)
        ->set('email', 'new-operator@example.com')
        ->set('role', 'operator')
        ->call('viaLink')
        ->assertDispatched('success');

    expect(TeamInvitation::whereEmail('new-operator@example.com')->first()?->role)->toBe('operator');
});

test('operator cannot invite anyone', function () {
    $this->actingAs($this->operator);

    Livewire::test(InviteLink::class)
        ->set('email', 'invited-by-operator@example.com')
        ->set('role', 'member')
        ->call('viaLink')
        ->assertDispatched('error');

    expect(TeamInvitation::whereEmail('invited-by-operator@example.com')->exists())->toBeFalse();
});

test('admin cannot invite a role above their own', function () {
    $this->actingAs($this->admin);

    Livewire::test(InviteLink::class)
        ->set('email', 'sneaky-owner@example.com')
        ->set('role', 'owner')
        ->call('viaLink')
        ->assertDispatched('error');

    expect(TeamInvitation::whereEmail('sneaky-owner@example.com')->exists())->toBeFalse();
});

test('invitation role must be a known role', function () {
    $this->actingAs($this->admin);

    Livewire::test(InviteLink::class)
        ->set('email', 'garbage-role@example.com')
        ->set('role', 'superadmin')
        ->call('viaLink')
        ->assertDispatched('error');

    expect(TeamInvitation::whereEmail('garbage-role@example.com')->exists())->toBeFalse();
});

test('owner can change a member to operator and team tokens are revoked', function () {
    $this->actingAs($this->member);
    $this->member->createToken('member-token', ['read']);
    expect($this->member->tokens()->count())->toBe(1);

    $this->actingAs($this->owner);
    Livewire::test(Member::class, ['member' => $this->member])
        ->call('makeOperator')
        ->assertDispatched('reloadWindow');

    expect($this->member->fresh()->roleInTeam($this->team->id))->toBe('operator')
        ->and($this->member->tokens()->count())->toBe(0);
});

test('operator cannot change member roles', function () {
    $this->actingAs($this->operator);

    Livewire::test(Member::class, ['member' => $this->member])
        ->call('makeOperator')
        ->assertDispatched('error');

    expect($this->member->fresh()->roleInTeam($this->team->id))->toBe('member');
});

test('an operator is promoted over a member when the last owner is deleted', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $owner->teams()->attach($team, ['role' => 'owner']);
    $operator = User::factory()->create();
    $operator->teams()->attach($team, ['role' => 'operator']);
    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'member']);

    $owner->delete();

    expect(Team::query()->find($team->id))->not->toBeNull()
        ->and($operator->fresh()->roleInTeam($team->id))->toBe('owner')
        ->and($member->fresh()->roleInTeam($team->id))->toBe('member');
});

test('an operator-only team is not deleted when the last owner is deleted', function () {
    $team = Team::factory()->create();
    $owner = User::factory()->create();
    $owner->teams()->attach($team, ['role' => 'owner']);
    $operator = User::factory()->create();
    $operator->teams()->attach($team, ['role' => 'operator']);

    $owner->delete();

    expect(Team::query()->find($team->id))->not->toBeNull()
        ->and($operator->fresh()->roleInTeam($team->id))->toBe('owner');
});

test('operator passes the backup download role gate but member does not', function () {
    $this->actingAs($this->member);
    $this->get('/download/backup/999999')->assertForbidden();

    $this->actingAs($this->operator);
    expect($this->get('/download/backup/999999')->status())->not->toBe(403);
});

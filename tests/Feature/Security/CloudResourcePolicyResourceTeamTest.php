<?php

use App\Livewire\Security\CloudInitScript\Show as CloudInitScriptShow;
use App\Livewire\Security\CloudProviderToken\Show as CloudProviderTokenShow;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }
    Once::flush();

    $this->resourceTeam = Team::factory()->create();
    $this->otherTeam = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->otherTeam->members()->attach($this->user->id, ['role' => 'owner']);

    $this->cloudToken = CloudProviderToken::factory()->create([
        'team_id' => $this->resourceTeam->id,
        'name' => 'Production Hetzner',
    ]);
    $this->cloudInitScript = CloudInitScript::query()->create([
        'team_id' => $this->resourceTeam->id,
        'name' => 'Bootstrap',
        'script' => "#cloud-config\nruncmd:\n  - echo original\n",
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->otherTeam]);
});

function switchCloudResourcePolicySessionTeam(Team $team): void
{
    session(['currentTeam' => $team]);
    Cache::flush();
    Once::flush();
}

function setCloudResourcePolicyRole(Team $team, User $user, string $role): void
{
    $team->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    $team->members()->updateExistingPivot($user->id, ['role' => $role]);
    $user->unsetRelation('teams');
    Cache::flush();
    Once::flush();
}

it('lets an admin of the resource team manage cloud resources while the session team is another team', function (string $ability) {
    setCloudResourcePolicyRole($this->resourceTeam, $this->user, 'admin');
    $user = $this->user->fresh();

    expect($user->can($ability, $this->cloudToken))->toBeTrue()
        ->and($user->can($ability, $this->cloudInitScript))->toBeTrue();
})->with(['view', 'update', 'delete']);

it('denies a member of the resource team who owns the session team', function (string $ability) {
    setCloudResourcePolicyRole($this->resourceTeam, $this->user, 'member');
    $user = $this->user->fresh();

    expect($user->can($ability, $this->cloudToken))->toBeFalse()
        ->and($user->can($ability, $this->cloudInitScript))->toBeFalse();
})->with(['view', 'update', 'delete']);

it('denies an outsider even when the session points to the resource team', function (string $ability) {
    switchCloudResourcePolicySessionTeam($this->resourceTeam);
    $user = $this->user->fresh();

    expect($user->can($ability, $this->cloudToken))->toBeFalse()
        ->and($user->can($ability, $this->cloudInitScript))->toBeFalse();
})->with(['view', 'update', 'delete']);

it('blocks a demoted cloud token editor after a session switch to a team they own', function (string $action) {
    setCloudResourcePolicyRole($this->resourceTeam, $this->user, 'admin');
    switchCloudResourcePolicySessionTeam($this->resourceTeam);

    $component = Livewire::test(CloudProviderTokenShow::class, ['cloud_token_uuid' => $this->cloudToken->uuid]);

    setCloudResourcePolicyRole($this->resourceTeam, $this->user, 'member');
    switchCloudResourcePolicySessionTeam($this->otherTeam);

    $component->set('name', 'Hijacked')->call($action)->assertForbidden();

    $fresh = CloudProviderToken::query()->find($this->cloudToken->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe('Production Hetzner');
})->with(['save', 'delete']);

it('blocks a demoted cloud-init script editor after a session switch to a team they own', function (string $action) {
    setCloudResourcePolicyRole($this->resourceTeam, $this->user, 'admin');
    switchCloudResourcePolicySessionTeam($this->resourceTeam);

    $component = Livewire::test(CloudInitScriptShow::class, ['cloud_init_script_uuid' => $this->cloudInitScript->uuid]);

    setCloudResourcePolicyRole($this->resourceTeam, $this->user, 'member');
    switchCloudResourcePolicySessionTeam($this->otherTeam);

    $component->set('name', 'Hijacked')->call($action)->assertForbidden();

    $fresh = CloudInitScript::query()->find($this->cloudInitScript->id);
    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe('Bootstrap');
})->with(['save', 'delete']);

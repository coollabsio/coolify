<?php

use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PersonalAccessToken;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use App\Policies\CloudInitScriptPolicy;
use App\Policies\CloudProviderTokenPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->team = Team::factory()->create();

    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);

    DB::table('private_keys')->insert([
        'uuid' => (string) Str::uuid(),
        'name' => 'Team SSH Key',
        'description' => 'Key for testing',
        'private_key' => 'test-key-content',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->privateKey = PrivateKey::where('team_id', $this->team->id)->first();
});

// --- Private Key Policy ---

test('admin can create private key', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', PrivateKey::class))->toBeTrue();
});

test('member cannot create private key', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', PrivateKey::class))->toBeFalse();
});

test('admin can view private key', function () {
    expect($this->admin->can('view', $this->privateKey))->toBeTrue();
});

test('member can view own team private key', function () {
    expect($this->member->can('view', $this->privateKey))->toBeTrue();
});

test('admin can update private key', function () {
    expect($this->admin->can('update', $this->privateKey))->toBeTrue();
});

test('member cannot update private key', function () {
    expect($this->member->can('update', $this->privateKey))->toBeFalse();
});

test('admin can delete private key', function () {
    expect($this->admin->can('delete', $this->privateKey))->toBeTrue();
});

test('member cannot delete private key', function () {
    expect($this->member->can('delete', $this->privateKey))->toBeFalse();
});

test('user from different team cannot view private key', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'admin']);

    expect($otherUser->can('view', $this->privateKey))->toBeFalse();
});

// --- Cloud Provider Token Policy ---

test('admin can create cloud provider token', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', CloudProviderToken::class))->toBeTrue();
});

test('member cannot create cloud provider token', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', CloudProviderToken::class))->toBeFalse();
});

test('admin can view any cloud provider tokens', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('viewAny', CloudProviderToken::class))->toBeTrue();
});

// --- Cloud Init Script Policy ---

test('admin can create cloud init script', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', CloudInitScript::class))->toBeTrue();
});

test('member cannot create cloud init script', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', CloudInitScript::class))->toBeFalse();
});

test('admin can view any cloud init scripts', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('viewAny', CloudInitScript::class))->toBeTrue();
});

test('cloud provider and cloud init policies are explicitly registered', function () {
    expect(Gate::getPolicyFor(CloudProviderToken::class))->toBeInstanceOf(CloudProviderTokenPolicy::class)
        ->and(Gate::getPolicyFor(CloudInitScript::class))->toBeInstanceOf(CloudInitScriptPolicy::class);
});

// --- Personal Access Token (API Token) Policy ---

test('any user can create personal access token', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('create', PersonalAccessToken::class))->toBeTrue();
});

test('admin can use root permissions for api tokens', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('useRootPermissions', PersonalAccessToken::class))->toBeTrue();
});

test('member cannot use root permissions for api tokens', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('useRootPermissions', PersonalAccessToken::class))->toBeFalse();
});

test('member cannot use write permissions for api tokens', function () {
    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect(auth()->user()->can('useWritePermissions', PersonalAccessToken::class))->toBeFalse();
});

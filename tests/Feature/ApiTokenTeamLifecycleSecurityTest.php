<?php

use App\Livewire\Security\ApiTokens;
use App\Livewire\Team\Member;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
        ['id' => 0],
        ['is_api_enabled' => true],
    ));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'admin']);
    session(['currentTeam' => $this->team]);
});

function bearerJson(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ];
}

test('removed member token cannot read team projects', function () {
    Project::create(['name' => 'Secret', 'team_id' => $this->team->id]);
    $token = $this->user->createToken('read-token', ['read'])->plainTextToken;

    $this->team->members()->detach($this->user->id);

    $this->withHeaders(bearerJson($token))
        ->getJson('/api/v1/projects')
        ->assertUnauthorized();
});

test('removed member token cannot create team projects', function () {
    $token = $this->user->createToken('write-token', ['write'])->plainTextToken;

    $this->team->members()->detach($this->user->id);

    $this->withHeaders(bearerJson($token))
        ->postJson('/api/v1/projects', ['name' => 'Should Not Exist'])
        ->assertUnauthorized();

    expect(Project::where('name', 'Should Not Exist')->exists())->toBeFalse();
});

test('downgraded member old write token cannot create team projects', function () {
    $token = $this->user->createToken('write-token', ['write'])->plainTextToken;

    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    $this->withHeaders(bearerJson($token))
        ->postJson('/api/v1/projects', ['name' => 'Downgrade Bypass'])
        ->assertForbidden();

    expect(Project::where('name', 'Downgrade Bypass')->exists())->toBeFalse();
});

test('admin removal through team member component revokes team tokens', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    $token = $this->user->createToken('read-token', ['read'])->accessToken;

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(Member::class, ['member' => $this->user])
        ->call('remove');

    expect(DB::table('personal_access_tokens')->where('id', $token->id)->exists())->toBeFalse();
});

test('role downgrade through team member component revokes team tokens', function () {
    $owner = User::factory()->create();
    $this->team->members()->attach($owner->id, ['role' => 'owner']);
    $token = $this->user->createToken('write-token', ['write'])->accessToken;

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(Member::class, ['member' => $this->user])
        ->call('makeReadonly');

    expect(DB::table('personal_access_tokens')->where('id', $token->id)->exists())->toBeFalse();
});

test('member cannot create write token through livewire token form', function () {
    $this->team->members()->updateExistingPivot($this->user->id, ['role' => 'member']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(ApiTokens::class)
        ->set('description', 'member-write-token')
        ->set('expiresInDays', 30)
        ->set('permissions', ['write'])
        ->call('addNewToken');

    expect($this->user->tokens()->where('name', 'member-write-token')->exists())->toBeFalse();
});

test('password change revokes user personal access tokens', function () {
    $token = $this->user->createToken('read-token', ['read'])->accessToken;

    $this->user->forceFill(['password' => Hash::make('new-password')])->save();

    expect(DB::table('personal_access_tokens')->where('id', $token->id)->exists())->toBeFalse();
});

test('team deletion revokes team bound personal access tokens', function () {
    $token = $this->user->createToken('read-token', ['read'])->accessToken;

    $this->team->delete();

    expect(DB::table('personal_access_tokens')->where('id', $token->id)->exists())->toBeFalse();
});

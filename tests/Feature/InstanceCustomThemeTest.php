<?php

use App\Livewire\Settings\CustomTheme;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Rules\ValidCustomTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
    $this->rootTeam = Team::factory()->create(['id' => 0]);
});

function actingAsRootTeamUser(string $role): User
{
    $user = User::factory()->create();
    test()->rootTeam->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => test()->rootTeam]);

    return $user;
}

test('an instance admin saves the normalized default theme', function () {
    actingAsRootTeamUser('owner');

    Livewire::test(CustomTheme::class)
        ->call('saveCustomTheme', ' #ABC, 112233 #445566,#778899 ')
        ->assertHasNoErrors()
        ->assertReturned('#aabbcc,#112233,#445566,#778899');

    expect(InstanceSettings::find(0)->custom_theme)->toBe('#aabbcc,#112233,#445566,#778899');
});

test('an instance admin removes the default theme with null', function () {
    actingAsRootTeamUser('admin');
    InstanceSettings::find(0)->update(['custom_theme' => '#111111,#222222,#333333,#444444']);
    Once::flush();

    Livewire::test(CustomTheme::class)->call('saveCustomTheme', null)->assertHasNoErrors();

    expect(InstanceSettings::find(0)->custom_theme)->toBeNull();
});

test('the default theme rejects anything but four hex colors', function (string $code) {
    actingAsRootTeamUser('owner');

    Livewire::test(CustomTheme::class)
        ->call('saveCustomTheme', $code)
        ->assertHasErrors('custom_theme');

    expect(InstanceSettings::find(0)->custom_theme)->toBeNull();
})->with([
    'three colors' => '#111111,#222222,#333333',
    'five colors' => '#111111,#222222,#333333,#444444,#555555',
    'not hex' => '#11111g,#222222,#333333,#444444',
    'four digits' => '#1111,#222222,#333333,#444444',
    'script' => '#111111,#222222,#333333,</script><script>alert(1)</script>',
    'css' => '#111111,#222222,#333333,red;background:url(x)',
]);

test('a member of the root team cannot set the default theme', function () {
    actingAsRootTeamUser('member');

    Livewire::test(CustomTheme::class)->assertForbidden();
    expect(InstanceSettings::find(0)->custom_theme)->toBeNull();
});

test('an owner of another team cannot set the default theme', function () {
    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(CustomTheme::class)->assertForbidden();
    expect(InstanceSettings::find(0)->custom_theme)->toBeNull();
});

test('the layout outputs the normalized default theme', function () {
    InstanceSettings::find(0)->update(['custom_theme' => '#ABC,#112233,#445566,#778899']);
    Once::flush();
    User::factory()->create();

    $this->get('/login')->assertOk()
        ->assertSee("window.instanceCustomTheme = '#aabbcc,#112233,#445566,#778899';", false);
});

test('the layout outputs null for a stored value that is not a valid code', function () {
    InstanceSettings::find(0)->forceFill(['custom_theme' => "#111111,#222222,#333333,'</script><script>alert(1)//"])->save();
    Once::flush();
    User::factory()->create();

    $this->get('/login')->assertOk()
        ->assertSee('window.instanceCustomTheme = null;', false)
        ->assertDontSee('<script>alert(1)', false);
});

test('ValidCustomTheme normalizes short colors and colors without a hash', function () {
    expect(ValidCustomTheme::normalize('FFF 000000,#AbC,123'))->toBe('#ffffff,#000000,#aabbcc,#112233')
        ->and(ValidCustomTheme::normalize(null))->toBeNull()
        ->and(ValidCustomTheme::normalize(['#111111']))->toBeNull();
});

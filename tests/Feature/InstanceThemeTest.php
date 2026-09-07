<?php

use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Livewire\Settings\Theme;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function actingAsThemeAdmin(): User
{
    $team = Team::forceCreate(['id' => 0, 'name' => 'Root Team', 'personal_team' => true]);
    $user = User::factory()->create(['id' => 0, 'email' => 'root@example.com', 'email_verified_at' => now()]);
    if (! $user->teams()->whereKey($team->id)->exists()) {
        $user->teams()->attach($team, ['role' => 'owner']);
    }
    session(['currentTeam' => $team]);
    test()->actingAs($user);

    return $user;
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.driver', 'file');
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
});

test('layout emits nothing when no theme is chosen', function () {
    actingAsThemeAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)->get('/settings/theme')
        ->assertOk()->assertDontSee('id="instance-theme"', escape: false);
});

test('layout inlines the chosen preset', function () {
    InstanceSettings::find(0)->update(['theme_preset' => 'nord']);
    Once::flush();
    actingAsThemeAdmin();

    $this->withoutMiddleware(DecideWhatToDoWithUser::class)->get('/settings/theme')
        ->assertOk()->assertSee('id="instance-theme"', escape: false)->assertSee('#88c0d0', escape: false);
});

test('layout inlines custom css and neutralises a closing style tag', function () {
    InstanceSettings::find(0)->update(['theme_preset' => 'custom', 'custom_css' => 'html.dark { --color-accent: #123456; }</style><script>alert(1)</script>']);
    Once::flush();
    actingAsThemeAdmin();

    $html = $this->withoutMiddleware(DecideWhatToDoWithUser::class)->get('/settings/theme')->assertOk()->getContent();

    expect($html)->toContain('--color-accent: #123456')
        ->not->toContain('</style><script>alert(1)</script>');
});

test('theme settings page saves preset and custom css', function () {
    actingAsThemeAdmin();

    Livewire::test(Theme::class)
        ->set('theme_preset', 'custom')
        ->set('custom_css', 'html.dark { --color-app: #000; }')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('reloadWindow');

    expect(InstanceSettings::find(0))->theme_preset->toBe('custom')->custom_css->toBe('html.dark { --color-app: #000; }');

    Livewire::test(Theme::class)->set('theme_preset', 'not-a-theme')->call('submit')->assertHasErrors('theme_preset');
    Livewire::test(Theme::class)->set('theme_preset', '')->call('submit')->assertHasNoErrors();
    expect(InstanceSettings::find(0)->theme_preset)->toBeNull();
});

test('theme presets are listed from resources/themes', function () {
    expect(themePresets())->toHaveKey('catppuccin-mocha')->and(themePresets()['catppuccin-mocha'])->toBe('Catppuccin Mocha');
});

test('pasting an iTerm2 colour scheme converts it to css on save', function () {
    $plist = file_get_contents(base_path('tests/Fixtures/iterm-theme.itermcolors'));

    $css = itermColorsToCss($plist);
    expect($css)->toContain('html.dark {')
        ->toContain('--color-panel: #2a1f1d;')      // Background Color
        ->toContain('--color-fg-dim: #e0dbb7;')     // Foreground Color
        ->toContain('--color-accent: #5a86ad;')     // Ansi 4
        ->toContain('--color-selected: #563c27;')   // Selection Color
        ->toContain('--color-error: #be2d26;');     // Ansi 1

    expect(itermColorsToCss('html.dark { --x: 1 }'))->toBeNull()
        ->and(itermColorsToCss('<plist><dict><key>Nope</key><string>x</string></dict></plist>'))->toBeNull();

    actingAsThemeAdmin();
    Livewire::test(Theme::class)
        ->set('theme_preset', 'custom')
        ->set('custom_css', $plist)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('custom_css', $css);

    expect(InstanceSettings::find(0)->custom_css)->toBe($css);
});

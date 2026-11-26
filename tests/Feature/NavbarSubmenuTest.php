<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
});

function renderNavbarAt(string $path, bool $mobile = false): string
{
    app()->instance('request', Request::create($path));

    return (string) test()->blade($mobile ? '<x-navbar />' : '<x-navbar desktop />');
}

function loginNavbarUser(string $role): void
{
    test()->team->members()->attach(test()->user->id, ['role' => $role]);
    test()->actingAs(test()->user);
    session(['currentTeam' => test()->team]);
}

test('a submenu page opens its submenu with the current page active', function () {
    loginNavbarUser('owner');

    $html = renderNavbarAt('/metrics');

    expect($html)
        ->toContain('Back to the main menu')
        ->toMatch('#aria-current="page"[^>]*>\s*<svg[^>]*>.*?</svg>\s*<span[^>]*>Metrics</span>#s');
});

test('a page outside every submenu starts on the main menu', function () {
    loginNavbarUser('owner');

    expect(renderNavbarAt('/servers'))->not->toContain('data-active-submenu="');
});

test('a submenu entry links to its first visible page', function () {
    loginNavbarUser('owner');

    expect(renderNavbarAt('/servers'))->toContain('href="'.route('analytics').'"');
});

test('the registries page is hidden from members in the integrations submenu', function () {
    loginNavbarUser('member');

    expect(renderNavbarAt('/sources'))
        ->toContain('Destinations')
        ->not->toContain('href="'.route('registries.index').'"');
});

test('the team submenu lists the team pages by role', function () {
    loginNavbarUser('owner');

    expect(renderNavbarAt('/team/members'))
        ->toContain('href="'.route('team.member.index').'"')
        ->toContain('href="'.route('team.audit-log').'"')
        ->toContain('href="'.route('team.danger-zone').'"');
});

test('members do not see the audit log or danger zone in the team submenu', function () {
    loginNavbarUser('member');

    expect(renderNavbarAt('/team'))
        ->toContain('href="'.route('team.member.index').'"')
        ->not->toContain('href="'.route('team.audit-log').'"')
        ->not->toContain('href="'.route('team.danger-zone').'"');
});

test('notifications and keys & tokens open their own submenus', function () {
    loginNavbarUser('owner');

    expect(renderNavbarAt('/notifications/discord'))
        ->toContain('href="'.route('notifications.slack').'"')
        ->toMatch('#aria-current="page"[^>]*>.*?discord\.svg#s');

    expect(renderNavbarAt('/security/api-tokens'))
        ->toContain('href="'.route('security.private-key.index').'"')
        ->toContain('Back to the main menu');
});

test('the admin link belongs to the main menu, not to submenus', function () {
    config()->set('app.env', 'local');
    $root = User::factory()->create(['id' => 0]);
    test()->team->members()->attach($root->id, ['role' => 'owner']);
    test()->actingAs($root);
    session(['currentTeam' => test()->team]);

    expect(renderNavbarAt('/team'))
        ->toMatch('#<li\s+x-show="!submenu"\s+x-cloak[^>]*>\s*<a title="Admin"#');
});

test('shared variables open their own submenu', function () {
    loginNavbarUser('owner');

    expect(renderNavbarAt('/shared-variables/servers'))
        ->toContain('Back to the main menu')
        ->toContain('href="'.route('shared-variables.team.index').'"')
        ->toMatch('#aria-current="page"[^>]*>\s*<svg[^>]*>.*?</svg>\s*<span[^>]*>Servers</span>#s');
});

test('instance settings open their own submenu for instance admins', function () {
    config()->set('app.env', 'local');
    $root = User::factory()->create(['id' => 0]);
    test()->team->members()->attach($root->id, ['role' => 'owner']);
    test()->actingAs($root);
    session(['currentTeam' => test()->team]);

    expect(renderNavbarAt('/settings/email'))
        ->toContain('Back to the main menu')
        ->toContain('href="'.route('settings.oauth').'"')
        ->toMatch('#aria-current="page"[^>]*>\s*<svg[^>]*>.*?</svg>\s*<span[^>]*>Email</span>#s');
});

test('a parent entry opens its submenu in place on mobile and desktop', function () {
    loginNavbarUser('owner');

    foreach ([renderNavbarAt('/servers', mobile: true), renderNavbarAt('/servers')] as $html) {
        expect($html)
            ->toContain('x-show="submenu === \'Monitoring\'"')
            ->toMatch('#<button type="button" title="Monitoring" @click="submenu = \'Monitoring\'#')
            ->not->toMatch('#<a title="Monitoring"#');
    }
});

test('the projects entry links to the projects page and is active on project pages', function () {
    loginNavbarUser('member');

    expect(renderNavbarAt('/project/abc'))
        ->toContain('href="'.route('project.index').'"')
        ->toMatch('#title="Projects"[^>]*menu-item-active#s')
        ->not->toMatch('#title="Dashboard"[^>]*menu-item-active#s');
});

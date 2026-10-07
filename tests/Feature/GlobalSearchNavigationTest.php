<?php

use App\Livewire\GlobalSearch;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Cache::flush();
});

function globalSearchNavigationLinks(Team $team, string $role = 'owner'): array
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return collect(Livewire::test(GlobalSearch::class)->call('openSearchModal')->get('allSearchableItems'))
        ->where('type', 'navigation')
        ->pluck('link')
        ->all();
}

function adminOnlyNavigationLinks(): array
{
    return [
        route('registries.index'),
        route('team.audit-log'),
        route('team.danger-zone'),
        route('security.cloud-tokens'),
        route('security.integration-tokens'),
        route('security.cloud-init-scripts'),
        route('terminal'),
    ];
}

it('lists every instance settings page for the root team', function () {
    $links = globalSearchNavigationLinks(Team::factory()->create(['id' => 0]));

    expect($links)->toContain(
        route('settings.index'),
        route('settings.advanced'),
        route('settings.updates'),
        route('settings.backup'),
        route('settings.email'),
        route('settings.oauth'),
    );
});

it('does not list instance settings pages for other teams', function () {
    $links = globalSearchNavigationLinks(Team::factory()->create());

    expect(collect($links)->filter(fn (string $link) => str_contains($link, '/settings')))->toBeEmpty();
});

it('lists team, security, notification and profile pages for a team owner', function () {
    $links = globalSearchNavigationLinks(Team::factory()->create());

    expect($links)->toContain(
        route('analytics'),
        route('shared-variables.server.index'),
        route('team.member.index'),
        route('security.api-tokens'),
        route('notifications.discord'),
        route('notifications.telegram'),
        route('notifications.slack'),
        route('notifications.pushover'),
        route('notifications.webhook'),
        route('profile.appearance'),
        ...adminOnlyNavigationLinks(),
    );
});

it('hides admin-only pages from team members', function () {
    $links = globalSearchNavigationLinks(Team::factory()->create(), 'member');

    expect($links)->toContain(route('team.member.index'), route('security.api-tokens'))
        ->not->toContain(...adminOnlyNavigationLinks());
});

it('does not leak admin-only pages to a member through the team search cache', function () {
    $team = Team::factory()->create();

    expect(globalSearchNavigationLinks($team))->toContain(...adminOnlyNavigationLinks());
    expect(globalSearchNavigationLinks($team, 'member'))->not->toContain(...adminOnlyNavigationLinks());
});

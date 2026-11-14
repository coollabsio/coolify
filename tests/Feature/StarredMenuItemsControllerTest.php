<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create(['show_boarding' => false]);
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function saveStarredMenuItems(string $menu, array $items): TestResponse
{
    return test()->putJson(route('profile.starred-menu-items'), ['menu' => $menu, 'items' => $items]);
}

it('saves the ordered starred pages of a menu and keeps the other menus', function () {
    saveStarredMenuItems('server', ['server.advanced', 'server.show'])->assertNoContent();
    saveStarredMenuItems('application', ['project.application.domains'])->assertNoContent();

    expect($this->user->fresh()->starred_menu_items)->toBe([
        'server' => ['server.advanced', 'server.show'],
        'application' => ['project.application.domains'],
    ]);
});

it('drops pages outside the menu prefix and routes that do not exist', function () {
    saveStarredMenuItems('application', [
        'project.application.domains',
        'server.show',
        'project.application.does-not-exist',
        'project.database.configuration',
    ])->assertNoContent();

    expect($this->user->fresh()->starred_menu_items)->toBe(['application' => ['project.application.domains']]);
});

it('clears the starred pages of a menu with an empty list', function () {
    saveStarredMenuItems('server', ['server.show'])->assertNoContent();
    saveStarredMenuItems('server', [])->assertNoContent();

    expect($this->user->fresh()->starred_menu_items)->toBe(['server' => []]);
});

it('rejects invalid input', function (array $payload, string $errorKey) {
    $this->putJson(route('profile.starred-menu-items'), $payload)->assertJsonValidationErrors($errorKey);

    expect($this->user->fresh()->starred_menu_items)->toBeNull();
})->with([
    'unknown menu' => [['menu' => 'team', 'items' => []], 'menu'],
    'missing items' => [['menu' => 'server'], 'items'],
    'duplicate items' => [['menu' => 'server', 'items' => ['server.show', 'server.show']], 'items.0'],
    'too many items' => [['menu' => 'server', 'items' => array_map(fn (int $i) => "server.page-{$i}", range(1, 51))], 'items'],
]);

it('requires a login', function () {
    auth()->logout();

    $this->putJson(route('profile.starred-menu-items'), ['menu' => 'server', 'items' => []])->assertUnauthorized();
});

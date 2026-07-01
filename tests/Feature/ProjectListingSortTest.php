<?php

use App\Livewire\Dashboard;
use App\Livewire\Project\Index as ProjectIndex;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeProjectForTeam(Team $team, string $name, int $createdDaysAgo, int $updatedDaysAgo): Project
{
    $project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'team_id' => $team->id,
    ]);

    // created_at / updated_at are auto-managed and not fillable, so set them
    // explicitly and persist without firing model events or touching timestamps.
    $project->forceFill([
        'created_at' => now()->subDays($createdDaysAgo),
        'updated_at' => now()->subDays($updatedDaysAgo),
    ])->saveQuietly();

    return $project;
}

function sortedNames($component): array
{
    return $component->instance()->sortedProjects->pluck('name')->all();
}

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    // Three projects whose name, created_at and updated_at orderings are each
    // different, so every sort option produces a provably distinct result.
    //
    //   name (A->Z, case-insensitive): alpha, Bravo, Charlie
    //   created_at (newest first):     Charlie, alpha, Bravo
    //   updated_at (newest first):     alpha, Charlie, Bravo
    makeProjectForTeam($this->team, 'Charlie', createdDaysAgo: 1, updatedDaysAgo: 2);
    makeProjectForTeam($this->team, 'alpha', createdDaysAgo: 2, updatedDaysAgo: 1);
    makeProjectForTeam($this->team, 'Bravo', createdDaysAgo: 3, updatedDaysAgo: 3);
});

test('dashboard renders successfully and exposes the sort control', function () {
    Livewire::test(Dashboard::class)
        ->assertSuccessful()
        ->assertSet('sort', 'name_asc')
        ->assertSee('Recently created')
        ->assertSee('Recently updated');
});

test('dashboard defaults to case-insensitive name ascending', function () {
    $component = Livewire::test(Dashboard::class);

    expect(sortedNames($component))->toBe(['alpha', 'Bravo', 'Charlie']);
});

test('dashboard sorts by name descending', function () {
    $component = Livewire::test(Dashboard::class)->set('sort', 'name_desc');

    expect(sortedNames($component))->toBe(['Charlie', 'Bravo', 'alpha']);
});

test('dashboard sorts by most recently created', function () {
    $component = Livewire::test(Dashboard::class)->set('sort', 'created_desc');

    expect(sortedNames($component))->toBe(['Charlie', 'alpha', 'Bravo']);
});

test('dashboard sorts by most recently updated', function () {
    $component = Livewire::test(Dashboard::class)->set('sort', 'updated_desc');

    expect(sortedNames($component))->toBe(['alpha', 'Charlie', 'Bravo']);
});

test('dashboard resets an unknown sort set at runtime to the default', function () {
    $component = Livewire::test(Dashboard::class)->set('sort', 'not-a-real-sort');

    $component->assertSet('sort', 'name_asc');
    expect(sortedNames($component))->toBe(['alpha', 'Bravo', 'Charlie']);
});

test('dashboard resets an unknown sort passed through the url to the default', function () {
    $component = Livewire::withQueryParams(['sort' => 'garbage'])->test(Dashboard::class);

    $component->assertSet('sort', 'name_asc');
    expect(sortedNames($component))->toBe(['alpha', 'Bravo', 'Charlie']);
});

test('projects index renders successfully and exposes the sort control', function () {
    Livewire::test(ProjectIndex::class)
        ->assertSuccessful()
        ->assertSet('sort', 'name_asc')
        ->assertSee('Recently created')
        ->assertSee('Recently updated');
});

test('projects index defaults to case-insensitive name ascending', function () {
    $component = Livewire::test(ProjectIndex::class);

    expect(sortedNames($component))->toBe(['alpha', 'Bravo', 'Charlie']);
});

test('projects index sorts by name descending', function () {
    $component = Livewire::test(ProjectIndex::class)->set('sort', 'name_desc');

    expect(sortedNames($component))->toBe(['Charlie', 'Bravo', 'alpha']);
});

test('projects index sorts by most recently created', function () {
    $component = Livewire::test(ProjectIndex::class)->set('sort', 'created_desc');

    expect(sortedNames($component))->toBe(['Charlie', 'alpha', 'Bravo']);
});

test('projects index sorts by most recently updated', function () {
    $component = Livewire::test(ProjectIndex::class)->set('sort', 'updated_desc');

    expect(sortedNames($component))->toBe(['alpha', 'Charlie', 'Bravo']);
});

test('projects index resets an unknown sort passed through the url to the default', function () {
    $component = Livewire::withQueryParams(['sort' => 'garbage'])->test(ProjectIndex::class);

    $component->assertSet('sort', 'name_asc');
    expect(sortedNames($component))->toBe(['alpha', 'Bravo', 'Charlie']);
});

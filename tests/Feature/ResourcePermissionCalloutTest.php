<?php

use App\Livewire\Project\Shared\ResourceOperations;
use App\Livewire\Project\Shared\Tags;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function resourcePermissionCalloutApplicationFor(string $role): Application
{
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => $role]);

    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    test()->actingAs($user);
    session(['currentTeam' => $team]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

it('shows one red insufficient permissions callout for resource operations when update is denied', function () {
    $application = resourcePermissionCalloutApplicationFor('member');

    $component = Livewire::test(ResourceOperations::class, ['resource' => $application])
        ->assertSee('Insufficient Permissions')
        ->assertSee('permission to modify this resource')
        ->assertSee('team administrator for access')
        ->assertDontSee('Access Restricted')
        ->assertDontSee("You don't have permission to clone resources")
        ->assertDontSee("You don't have permission to move resources");

    expect(substr_count($component->html(), 'Insufficient Permissions'))->toBe(1)
        ->and($component->html())->toContain('bg-red-50')
        ->and($component->html())->not->toContain('bg-warning-50');
});

it('shows the red insufficient permissions callout for tags when update is denied', function () {
    $application = resourcePermissionCalloutApplicationFor('member');

    $component = Livewire::test(Tags::class, ['resource' => $application])
        ->assertSee('Insufficient Permissions')
        ->assertSee('permission to manage this resource')
        ->assertSee('team administrator for access')
        ->assertDontSee('Access Restricted')
        ->assertDontSee("You don't have permission to manage tags");

    expect(substr_count($component->html(), 'Insufficient Permissions'))->toBe(1)
        ->and($component->html())->toContain('bg-red-50')
        ->and($component->html())->not->toContain('bg-warning-50');
});

it('does not use yellow permission callouts in blade views', function () {
    $offendingFiles = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))))
        ->filter(fn (SplFileInfo $file) => $file->isFile() && $file->getExtension() === 'php')
        ->filter(function (SplFileInfo $file) {
            $contents = file_get_contents($file->getPathname());

            return str_contains($contents, 'type="warning" title="Permission Required"')
                || str_contains($contents, 'title="Access Restricted"');
        })
        ->map(fn (SplFileInfo $file) => str_replace(base_path().'/', '', $file->getPathname()))
        ->values()
        ->all();

    expect($offendingFiles)->toBeEmpty();
});

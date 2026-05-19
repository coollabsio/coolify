<?php

use App\Livewire\Project\Application\General;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

describe('Application Redirect', function () {
    test('setRedirect persists the redirect value to the database', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'fqdn' => 'https://example.com,https://www.example.com',
            'redirect' => 'both',
        ]);

        Livewire::test(General::class, ['application' => $application])
            ->assertSuccessful()
            ->set('redirect', 'www')
            ->call('setRedirect')
            ->assertDispatched('success');

        $application->refresh();
        expect($application->redirect)->toBe('www');
    });

    test('setRedirect rejects www redirect when no www domain exists', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'fqdn' => 'https://example.com',
            'redirect' => 'both',
        ]);

        Livewire::test(General::class, ['application' => $application])
            ->assertSuccessful()
            ->set('redirect', 'www')
            ->call('setRedirect')
            ->assertDispatched('error');

        $application->refresh();
        expect($application->redirect)->toBe('both');
    });
});

<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
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

describe('Application Source with localhost key (id=0)', function () {
    test('renders deploy key section when private_key_id is 0', function () {
        $privateKey = PrivateKey::create([
            'id' => 0,
            'name' => 'localhost',
            'private_key' => 'test-key-content',
            'team_id' => $this->team->id,
        ]);

        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'private_key_id' => 0,
        ]);

        Livewire::test(Source::class, ['application' => $application])
            ->assertSuccessful()
            ->assertSet('privateKeyId', 0)
            ->assertSee('Deploy Key');
    });

    test('shows no source connected section when private_key_id is null', function () {
        $application = Application::factory()->create([
            'environment_id' => $this->environment->id,
            'private_key_id' => null,
        ]);

        Livewire::test(Source::class, ['application' => $application])
            ->assertSuccessful()
            ->assertSet('privateKeyId', null)
            ->assertDontSee('Deploy Key')
            ->assertSee('No source connected');
    });
});

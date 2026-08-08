<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('uses card-style git source triggers instead of plain form buttons', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/source.blade.php'));

    expect($view)
        ->toContain('<x-slot:trigger>')
        ->toContain('label="Current"')
        ->toContain('aria-current="true"')
        ->toContain('<x-git-icon')
        ->toContain('rounded-xl border')
        ->not->toContain('<x-slot:customButton>');
});

it('renders the current source as a selected card and alternatives as switchable cards', function () {
    $current = GithubApp::create([
        'name' => 'coolify-laravel-dev-public',
        'organization' => 'coollabsio',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 111,
        'installation_id' => 222,
        'client_id' => 'client',
        'client_secret' => 'secret',
        'webhook_secret' => 'webhook',
    ]);

    $other = GithubApp::create([
        'name' => 'coolify-examples-app',
        'organization' => 'coollabsio',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 333,
        'installation_id' => 444,
        'client_id' => 'client-2',
        'client_secret' => 'secret-2',
        'webhook_secret' => 'webhook-2',
    ]);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'private_key_id' => null,
        'source_id' => $current->id,
        'source_type' => GithubApp::class,
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'v4.x',
    ]);

    Livewire::test(Source::class, ['application' => $application])
        ->assertOk()
        ->assertSee('Git source', false)
        ->assertSee('coolify-laravel-dev-public', false)
        ->assertSee('Current', false)
        ->assertSee('GitHub · coollabsio', false)
        ->assertSee('coolify-examples-app', false)
        ->assertSee('Change Git source?', false);
});

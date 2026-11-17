<?php

use App\Livewire\Project\Application\Source;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
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

test('a GitLab source is not hidden by a GitHub source sharing the same numeric id', function () {
    $githubApp = GithubApp::create([
        'name' => 'gh',
        'team_id' => $this->team->id,
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'is_public' => false,
        'app_id' => 123,
    ]);

    $gitlabApp = GitlabApp::create([
        'name' => 'gl',
        'team_id' => $this->team->id,
        'api_url' => 'https://gitlab.example.test/api/v4',
        'html_url' => 'https://gitlab.example.test',
        'is_public' => false,
        'access_token' => 'token',
        'refresh_token' => 'refresh',
        'expires_at' => time() + 3600,
    ]);

    // The two source tables auto-increment independently, so the first row in each shares id 1.
    expect($gitlabApp->id)->toBe($githubApp->id);

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'private_key_id' => null,
        'source_id' => $githubApp->id,
        'source_type' => GithubApp::class,
    ]);

    $component = Livewire::test(Source::class, ['application' => $application]);
    $sources = $component->get('sources');

    expect($sources->contains(fn ($s) => $s instanceof GitlabApp && $s->id === $gitlabApp->id))->toBeTrue();
    expect($sources->contains(fn ($s) => $s instanceof GithubApp && $s->id === $githubApp->id))->toBeFalse();

    // The GitLab source renders as selectable, not flagged as the current GitHub source despite the shared id.
    $component->assertSee('gl')->assertDontSee('(current)');
});

<?php

use App\Livewire\Source\Gitea\Change as GiteaChange;
use App\Livewire\Source\Github\Change as GithubChange;
use App\Livewire\Source\Gitlab\Change as GitlabChange;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.driver', 'file');
    config()->set('cache.default', 'array');

    InstanceSettings::forceCreate(['id' => 0]);

    $this->teamA = Team::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB = User::factory()->create();
    $this->teamB->members()->attach($this->userB->id, ['role' => 'owner']);

    $this->actingAs($this->userB);
    session(['currentTeam' => $this->teamB]);
});

function attachSourceApplication(Team $team, string $name, object $source): Application
{
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'name' => $name,
        'environment_id' => $environment->id,
        'source_id' => $source->id,
        'source_type' => $source::class,
    ]);
}

function systemWideGitSource(string $type, Team $owner): object
{
    $attributes = [
        'name' => "System wide {$type}",
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $owner->id,
        'is_system_wide' => true,
        'is_public' => false,
    ];

    return match ($type) {
        'github' => GithubApp::create([
            ...$attributes,
            'api_url' => 'https://api.github.com',
            'html_url' => 'https://github.com',
            'private_key_id' => PrivateKey::withoutEvents(fn () => PrivateKey::forceCreate([
                'uuid' => 'resources-scope-key',
                'name' => 'Key',
                'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nKEY\n-----END OPENSSH PRIVATE KEY-----",
                'fingerprint' => 'resources-scope-fingerprint',
                'team_id' => $owner->id,
            ]))->id,
        ]),
        'gitlab' => GitlabApp::create([
            ...$attributes,
            'api_url' => 'https://gitlab.com/api/v4',
            'html_url' => 'https://gitlab.com',
        ]),
        'gitea' => GiteaApp::create([
            ...$attributes,
            'api_url' => 'https://gitea.com/api/v1',
            'html_url' => 'https://gitea.com',
        ]),
    };
}

test('a system wide source lists only the applications of the current team', function (string $type, string $component, string $queryParameter) {
    $source = systemWideGitSource($type, $this->teamA);
    $foreignApplication = attachSourceApplication($this->teamA, 'team-a-secret-app', $source);
    $ownApplication = attachSourceApplication($this->teamB, 'team-b-own-app', $source);

    $applications = Livewire::withQueryParams([$queryParameter => $source->uuid])
        ->test($component)
        ->assertOk()
        ->get('applications');

    expect($applications->pluck('id')->all())->toBe([$ownApplication->id]);
})->with([
    'github' => ['github', GithubChange::class, 'github_app_uuid'],
    'gitlab' => ['gitlab', GitlabChange::class, 'gitlab_app_uuid'],
    'gitea' => ['gitea', GiteaChange::class, 'gitea_app_uuid'],
]);

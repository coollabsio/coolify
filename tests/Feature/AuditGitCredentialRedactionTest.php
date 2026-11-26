<?php

use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutDefer();

    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('audit changes do not store credentials embedded in the git repository url', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'git_repository' => 'https://oauth2:glpat-old@token@gitlab.com/org/old.git',
    ]);
    AuditEvent::query()->delete();

    $application->update(['git_repository' => 'https://oauth2:glpat-new-token@gitlab.com/org/repo.git']);

    $event = AuditEvent::query()->sole();

    expect($event->changes['git_repository'])->toBe([
        'old' => 'https://gitlab.com/org/old.git',
        'new' => 'https://gitlab.com/org/repo.git',
    ])
        ->and(json_encode($event->changes))->not->toContain('glpat')
        ->and(json_encode($event->metadata))->not->toContain('glpat');
});

test('audit changes keep git repository urls without credentials unchanged', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create(['environment_id' => $environment->id]);
    AuditEvent::query()->delete();

    $application->update(['git_repository' => 'git@github.com:org/repo.git']);

    expect(AuditEvent::query()->sole()->changes['git_repository']['new'])->toBe('git@github.com:org/repo.git');
});

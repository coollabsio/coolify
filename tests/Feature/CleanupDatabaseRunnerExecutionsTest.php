<?php

use App\Enums\GithubRunnerStatus;
use App\Models\AuditEvent;
use App\Models\GithubApp;
use App\Models\GithubRunnerExecution;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function cleanupRunnerTestExecution(GithubApp $githubApp, GithubRunnerStatus $status, int $ageInDays): GithubRunnerExecution
{
    static $jobId = 9000;

    $execution = GithubRunnerExecution::create([
        'github_app_id' => $githubApp->id,
        'trigger_workflow_job_id' => ++$jobId,
        'status' => $status,
    ]);
    GithubRunnerExecution::query()->whereKey($execution->id)->toBase()->update(['created_at' => now()->subDays($ageInDays)]);

    return $execution;
}

beforeEach(function () {
    $team = Team::factory()->create();
    $this->githubApp = GithubApp::create([
        'name' => 'cleanup-runner-app',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $team->id,
        'is_system_wide' => false,
    ]);
});

it('deletes old finished runner executions and keeps recent and unfinished ones', function () {
    $oldFinished = collect([
        GithubRunnerStatus::Completed,
        GithubRunnerStatus::Failed,
        GithubRunnerStatus::Cancelled,
        GithubRunnerStatus::TimedOut,
    ])->map(fn (GithubRunnerStatus $status) => cleanupRunnerTestExecution($this->githubApp, $status, 61));
    $recentFinished = cleanupRunnerTestExecution($this->githubApp, GithubRunnerStatus::Completed, 5);
    $oldUnfinished = collect(GithubRunnerStatus::active())
        ->map(fn (GithubRunnerStatus $status) => cleanupRunnerTestExecution($this->githubApp, $status, 61));

    $this->artisan('cleanup:database --yes')
        ->expectsOutputToContain('Delete 4 entries from github_runner_executions.')
        ->assertSuccessful();

    expect(GithubRunnerExecution::query()->pluck('id')->sort()->values()->all())
        ->toBe($oldUnfinished->push($recentFinished)->pluck('id')->sort()->values()->all())
        ->and($oldFinished)->toHaveCount(4);
});

it('keeps old finished runner executions in a dry run', function () {
    cleanupRunnerTestExecution($this->githubApp, GithubRunnerStatus::Completed, 61);

    $this->artisan('cleanup:database')
        ->expectsOutputToContain('Delete 1 entries from github_runner_executions.')
        ->assertSuccessful();

    expect(GithubRunnerExecution::query()->count())->toBe(1);
});

it('prunes expired audit events in batches', function () {
    $expired = now()->subDays(91);
    $rows = collect(range(1, 2500))->map(fn (int $i) => [
        'event' => 'ui.project.updated',
        'source' => 'ui',
        'action' => 'updated',
        'level' => 'info',
        'actor_type' => 'user',
        'description' => 'Project updated',
        'created_at' => $expired,
    ]);
    $rows->chunk(500)->each(fn ($chunk) => DB::table('audit_events')->insert($chunk->values()->all()));
    $recent = AuditEvent::factory()->create(['created_at' => now()->subDays(89)]);

    $deleteQueries = 0;
    DB::listen(function ($query) use (&$deleteQueries): void {
        if (str_starts_with(strtolower($query->sql), 'delete from "audit_events"')) {
            $deleteQueries++;
        }
    });

    $deleted = AuditEvent::pruneExpired();

    expect($deleted)->toBe(2500)
        ->and(AuditEvent::query()->pluck('id')->all())->toBe([$recent->id])
        ->and($deleteQueries)->toBeGreaterThan(1);
});

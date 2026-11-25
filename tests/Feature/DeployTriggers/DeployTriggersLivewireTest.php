<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\EvaluatePush;
use App\Livewire\Project\Application\Deployment\Index;
use App\Livewire\Project\Application\Deployment\Show;
use App\Livewire\Project\Application\DeployTriggers;
use App\Livewire\Settings\Advanced;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->fixture = deployTriggerFixture();
    $this->application = deployTriggerApplication($this->fixture, ['base_directory' => '/apps/web', 'name' => 'web']);
    $this->actingAs($this->fixture['user']);
    session(['currentTeam' => $this->fixture['team']]);
});

function actAsMemberOf(array $fixture, string $role): User
{
    $user = User::factory()->create();
    $fixture['team']->members()->attach($user->id, ['role' => $role]);
    test()->actingAs($user);
    session(['currentTeam' => $fixture['team']]);

    return $user;
}

it('saves the toggle', function () {
    Livewire::test(DeployTriggers::class, ['application' => $this->application])
        ->set('isSmartTriggersEnabled', false)
        ->call('instantSave')
        ->assertHasNoErrors();

    expect($this->application->settings->refresh()->is_smart_triggers_enabled)->toBeFalse();
});

it('does not let a read-only member change the toggle', function () {
    actAsMemberOf($this->fixture, 'member');

    Livewire::test(DeployTriggers::class, ['application' => $this->application])
        ->set('isSmartTriggersEnabled', false)
        ->call('instantSave');

    expect($this->application->settings->refresh()->is_smart_triggers_enabled)->toBeTrue();
});

it('acts on the application team after the session switches teams', function () {
    $component = Livewire::test(DeployTriggers::class, ['application' => $this->application]);
    $otherTeam = Team::factory()->create();
    $otherTeam->members()->attach($this->fixture['user']->id, ['role' => 'owner']);
    session(['currentTeam' => $otherTeam]);

    $component->set('isSmartTriggersEnabled', false)->call('instantSave');

    expect($this->application->settings->refresh()->is_smart_triggers_enabled)->toBeFalse();
});

it('dispatches one dry run and rate limits evaluate now', function () {
    RateLimiter::clear('evaluate-now:'.$this->application->uuid);
    $this->application->forceFill(['trigger_base_commit' => str_repeat('d', 40)])->save();
    $component = Livewire::test(DeployTriggers::class, ['application' => $this->application]);

    foreach (range(1, 4) as $attempt) {
        $component->call('evaluateNow');
    }

    $component->assertDispatched('error');
    Queue::assertPushed(EvaluatePush::class, 1);
    Queue::assertPushed(EvaluatePush::class, fn (EvaluatePush $job) => $job->dryRun
        && $job->context->after === str_repeat('d', 40)
        && $job->context->ref === 'refs/heads/main'
        && $job->context->teamId === $this->fixture['team']->id
        && $job->applicationIds === [$this->application->id]);
});

it('asks for a first deployment before evaluating', function () {
    Livewire::test(DeployTriggers::class, ['application' => $this->application])
        ->call('evaluateNow')
        ->assertDispatched('error');

    Queue::assertNotPushed(EvaluatePush::class);
});

it('explains the trigger state', function (string $case, string $copy) {
    match ($case) {
        'not narrowable' => $this->application->update(['base_directory' => '/']),
        'watch paths' => $this->application->update(['watch_paths' => 'apps/web/**']),
        'instance off' => InstanceSettings::get()->update(['is_smart_triggers_enabled' => false]),
        'evaluated' => $this->application->forceFill(['trigger_set' => [
            'display' => ['apps/web/**', 'packages/ui/**'], 'resolvers' => ['pnpm'], 'anchor' => ['package' => '@acme/web'],
            'widened' => [], 'evaluated_commit' => str_repeat('a', 40), 'last_error' => null,
        ]])->save(),
        'last error' => $this->application->forceFill(['trigger_set' => [
            'display' => [], 'evaluated_commit' => str_repeat('a', 40), 'last_error' => 'server unreachable',
        ]])->save(),
        default => null,
    };

    Livewire::test(DeployTriggers::class, ['application' => $this->application->refresh()])->assertSee($copy, escape: false);
})->with([
    ['not evaluated', 'Not evaluated yet. The next push evaluates it.'],
    ['not narrowable', 'builds from the root of the repository, so every push deploys'],
    ['watch paths', 'override detection'],
    ['instance off', 'Turned off for this instance'],
    ['evaluated', 'packages/ui/**'],
    ['last error', "couldn't be evaluated (server unreachable), so it deployed"],
]);

function skippedRow($application): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => $application->id, 'deployment_uuid' => 'skipped-'.$application->id, 'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::SKIPPED->value, 'commit' => str_repeat('b', 40), 'skipped_count' => 3,
        'first_skipped_commit' => str_repeat('a', 40), 'server_id' => $application->destination->server->id,
        'trigger_reason' => ['reason_code' => 'no_match', 'reason' => 'None of the 2 changed files affect this application.', 'matches' => []],
    ]);
}

function showTriggerDeployment($test, ApplicationDeploymentQueue $row)
{
    return Livewire::test(Show::class, [
        'project_uuid' => $test->fixture['environment']->project->uuid,
        'environment_uuid' => $test->fixture['environment']->uuid,
        'application_uuid' => $test->application->uuid,
        'deployment_uuid' => $row->deployment_uuid,
    ]);
}

it('shows why pushes were skipped and deploys anyway', function () {
    $row = skippedRow($this->application);

    showTriggerDeployment($this, $row)
        ->assertSee('Skipped 3 pushes')
        ->assertSee('None of the 2 changed files affect this application.')
        ->call('deploySkippedPush');

    expect(ApplicationDeploymentQueue::query()->where('application_id', $this->application->id)->where('status', '!=', 'skipped')->sole()->commit)->toBe(str_repeat('b', 40));
});

it('does not deploy anyway a row that is not skipped', function () {
    $row = skippedRow($this->application);
    $row->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);

    showTriggerDeployment($this, $row)->call('deploySkippedPush');

    expect(ApplicationDeploymentQueue::query()->count())->toBe(1);
});

it('shows what triggered a deployment', function () {
    $row = skippedRow($this->application);
    $row->update(['status' => ApplicationDeploymentStatus::FINISHED->value, 'trigger_reason' => [
        'reason_code' => 'matched', 'reason' => 'packages/ui/src/Button.tsx changed.',
        'matches' => [['file' => 'packages/ui/src/Button.tsx', 'rule' => 'workspace', 'via' => ['apps/web (@acme/web)', '@acme/ui']]],
    ]]);

    showTriggerDeployment($this, $row)->assertSee('packages/ui/src/Button.tsx')->assertSee('@acme/ui');
});

it('labels folded skips in the deployment list', function () {
    skippedRow($this->application);

    Livewire::test(Index::class, [
        'project_uuid' => $this->fixture['environment']->project->uuid,
        'environment_uuid' => $this->fixture['environment']->uuid,
        'application_uuid' => $this->application->uuid,
    ])->assertSee('Skipped · 3 pushes');
});

it('lets instance admins turn smart triggers off for the instance', function () {
    InstanceSettings::get()->update(['is_api_enabled' => false]);
    $rootTeam = Team::find(0) ?? Team::forceCreate(['id' => 0, 'name' => 'Root', 'personal_team' => false]);
    $rootTeam->members()->syncWithoutDetaching([$this->fixture['user']->id => ['role' => 'owner']]);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(Advanced::class)->set('is_smart_triggers_enabled', false)->call('instantSave');

    expect(InstanceSettings::get()->is_smart_triggers_enabled)->toBeFalse();
});

it('creates the preview when a skipped pull request push is deployed anyway', function () {
    $row = skippedRow($this->application);
    $row->update(['pull_request_id' => 7, 'git_type' => 'github', 'trigger_reason' => [
        'reason_code' => 'no_match', 'reason' => 'No match.', 'matches' => [], 'pull_request_html_url' => 'https://github.com/acme/mono/pull/7',
    ]]);

    showTriggerDeployment($this, $row)->call('deploySkippedPush');

    $deployment = ApplicationDeploymentQueue::query()->where('application_id', $this->application->id)->where('status', '!=', 'skipped')->sole();
    expect($deployment->pull_request_id)->toBe(7)
        ->and($deployment->git_type)->toBe('github')
        ->and(ApplicationPreview::query()->where('application_id', $this->application->id)->where('pull_request_id', 7)->sole()->pull_request_html_url)->toBe('https://github.com/acme/mono/pull/7');
});

it('does not let a read-only member deploy a skipped push or evaluate', function () {
    $row = skippedRow($this->application);
    $this->application->forceFill(['trigger_base_commit' => str_repeat('d', 40)])->save();
    actAsMemberOf($this->fixture, 'member');

    showTriggerDeployment($this, $row)->call('deploySkippedPush');
    Livewire::test(DeployTriggers::class, ['application' => $this->application])->call('evaluateNow');

    expect(ApplicationDeploymentQueue::query()->count())->toBe(1);
    Queue::assertNotPushed(EvaluatePush::class);
});

it('does not deploy anyway a skipped push that a newer deployment follows', function () {
    $row = skippedRow($this->application);
    ApplicationDeploymentQueue::create(['application_id' => $this->application->id, 'deployment_uuid' => 'newer', 'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FINISHED->value, 'commit' => str_repeat('e', 40)]);

    showTriggerDeployment($this, $row)
        ->assertDontSee('Deploy anyway')
        ->call('deploySkippedPush')
        ->assertDispatched('error');

    expect(ApplicationDeploymentQueue::query()->count())->toBe(2);
});

it('stops polling a skipped push', function () {
    showTriggerDeployment($this, skippedRow($this->application))->assertSet('isKeepAliveOn', false);
});

it('does not claim that a failed evaluate now deployed', function () {
    $this->application->forceFill(['trigger_set' => [
        'display' => [], 'evaluated_commit' => str_repeat('a', 40), 'last_error' => 'server unreachable', 'last_error_dry_run' => true,
    ]])->save();

    Livewire::test(DeployTriggers::class, ['application' => $this->application->refresh()])
        ->assertSee('Evaluate now could not finish (server unreachable).', escape: false)
        ->assertDontSee('so it deployed');
});

it('shows the new trigger paths once evaluate now finishes', function () {
    RateLimiter::clear('evaluate-now:'.$this->application->uuid);
    $this->application->forceFill(['trigger_base_commit' => str_repeat('d', 40)])->save();
    $component = Livewire::test(DeployTriggers::class, ['application' => $this->application])->call('evaluateNow');
    $this->application->forceFill(['trigger_set' => [
        'display' => ['apps/web/**'], 'evaluated_commit' => str_repeat('d', 40), 'evaluated_at' => now()->toIso8601String(), 'last_error' => null,
    ]])->save();

    $component->call('checkEvaluation')->assertSee('apps/web/**')->assertSet('isEvaluating', false);
});

it('updates the trigger state after the general settings change', function () {
    $component = Livewire::test(DeployTriggers::class, ['application' => $this->application])->assertSee('Not evaluated yet');
    $this->application->update(['base_directory' => '/']);

    $component->dispatch('configurationChanged')->assertSee('builds from the root of the repository');
});

it('names where each trigger path comes from', function () {
    $this->application->forceFill(['trigger_set' => [
        'display' => ['build_context: apps/web/**', 'workspace: packages/ui/**', 'build_context: !apps/web/test/**'], 'evaluated_commit' => str_repeat('a', 40),
    ]])->save();

    Livewire::test(DeployTriggers::class, ['application' => $this->application->refresh()])
        ->assertSeeInOrder(['apps/web/**', 'Build context', 'packages/ui/**', 'Workspace dependency', 'apps/web/test/**', 'Build context, excluded'])
        ->assertDontSee('workspace:');
});

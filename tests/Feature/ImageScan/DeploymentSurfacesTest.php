<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Enums\ProcessStatus;
use App\Jobs\ApplicationPullRequestUpdateJob;
use App\Livewire\Dashboard\ActiveDeployments;
use App\Livewire\Project\Application\Deployment\Index;
use App\Livewire\Project\Application\Deployment\Show;
use App\Livewire\Project\Application\Heading;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Cache::flush();
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array']);
    $this->withoutVite();
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->admin = surfacesMember($this->team, 'admin');
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function surfacesMember(Team $team, string $role = 'member', ?array $access = null): User
{
    $user = User::factory()->create();
    $user->teams()->attach($team, ['role' => $role, 'access' => $access === null ? null : json_encode($access)]);

    return $user->fresh();
}

/**
 * @return array<string, mixed>
 */
function surfacesBlockedResult(): array
{
    return [
        'blocked' => true,
        'scan_ran' => true,
        'error' => null,
        'count' => 1,
        'headline' => 'Deploy blocked: this image has a security problem that attackers are using.',
        'top' => [['id' => 'CVE-2026-1234', 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13', 'fixed_in' => '3.0.15', 'kev' => false]],
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function surfacesDeployment(array $attributes = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create([
        'application_id' => test()->application->id,
        'deployment_uuid' => (string) Str::uuid(),
        'server_id' => test()->server->id,
        'server_name' => test()->server->name,
        'destination_id' => test()->application->destination_id,
        'commit' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
        'pull_request_id' => 0,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        ...$attributes,
    ]);
}

function surfacesShow(User $user, ApplicationDeploymentQueue $deployment, ?Team $sessionTeam = null): Testable
{
    test()->actingAs($user);
    session(['currentTeam' => $sessionTeam ?? test()->team]);

    return Livewire::test(Show::class, [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'application_uuid' => test()->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]);
}

function surfacesPullRequestBody(ApplicationDeploymentQueue $deployment): string
{
    $preview = ApplicationPreview::forceCreate([
        'application_id' => test()->application->id,
        'pull_request_id' => 7,
        'pull_request_html_url' => 'https://github.com/coollabsio/app/pull/7',
    ]);
    $job = new ApplicationPullRequestUpdateJob(test()->application->fresh(), $preview, ProcessStatus::ERROR, $deployment->deployment_uuid);

    return (fn (): string => $this->commentBody('app'))->call($job);
}

describe('status', function () {
    it('lists a blocked deployment as Blocked in the history', function () {
        surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Index::class, [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])->assertSee('Blocked');
    });

    it('shows a failed deployment without a gate result as Failed', function () {
        surfacesDeployment();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Index::class, [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
        ])->assertSee('Failed')->assertDontSee('Blocked');
    });

    it('shows Blocked in the recent deployments of the dashboard', function () {
        surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(ActiveDeployments::class)->assertSee('Blocked');
    });
});

describe('log toolbar', function () {
    it('offers Deploy anyway to a user with write access', function () {
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);

        surfacesShow($this->admin, $deployment)
            ->assertSee('Blocked')
            ->assertSee('Deploy blocked: this image has a security problem that attackers are using.')
            ->assertSee('Open Security')
            ->assertSee('Deploy anyway')
            ->assertDontSee('Ask someone with write access to deploy anyway.');
    });

    it('asks a deploy-only user to find someone with write access', function () {
        $deployer = surfacesMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'deploy']],
        ]);
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);

        surfacesShow($deployer, $deployment)
            ->assertSee('Ask someone with write access to deploy anyway.')
            ->assertDontSeeHtml('wire:click="deployAnyway"');
    });

    it('shows Not scanned with the reason when the scan could not run', function () {
        $deployment = surfacesDeployment([
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'scan_gate_result' => ['blocked' => false, 'scan_ran' => false, 'error' => 'scanning_off', 'count' => 0, 'headline' => 'Scan skipped: Turn on image scanning on main-server.', 'top' => []],
        ]);

        surfacesShow($this->admin, $deployment)
            ->assertSee('Not scanned')
            ->assertSee('Scan skipped: Turn on image scanning on main-server.')
            ->assertSee('Open Security')
            ->assertDontSee('Deploy anyway');
    });

    it('shows the action of the Security page for the blocked image only when a candidate is better', function () {
        $this->application->forceFill(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'postgres', 'docker_registry_image_tag' => '16.3-alpine'])->saveQuietly();
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        $scan = ImageScan::create([
            'server_id' => $this->server->id,
            'image_id' => 'sha256:'.str_repeat('a', 64),
            'resource_type' => $this->application->getMorphClass(),
            'resource_id' => $this->application->id,
            'image_ref' => 'postgres:16.3-alpine',
            'deployment_queue_id' => $deployment->id,
            'status' => ImageScanStatus::Completed,
            'findings' => [[
                'id' => 'CVE-2026-1234', 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13',
                'type' => 'apk', 'artifactId' => 'a1', 'fixedIn' => '3.0.15', 'fixState' => 'fixed', 'kev' => true,
            ]],
            'candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [
                ['ref' => 'postgres:16.3-alpine', 'kind' => 'same_tag', 'better' => false, 'fixed_ids' => []],
            ]],
            'scanned_at' => now(),
        ]);

        surfacesShow($this->admin, $deployment)->assertDontSee('Update to');

        $scan->update(['candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [
            ['ref' => 'postgres:16.4-alpine', 'kind' => 'line', 'better' => true, 'fixed_ids' => ['CVE-2026-1234']],
        ]]]);

        surfacesShow($this->admin, $deployment)->assertSee('Update to 16.4-alpine.');

        $scan->update(['candidates' => ['status' => 'ok', 'checked_at' => now()->toIso8601String(), 'entries' => [
            ['ref' => 'postgres:17.0-alpine', 'kind' => 'major', 'better' => true, 'fixed_ids' => ['CVE-2026-1234']],
        ]]]);

        surfacesShow($this->admin, $deployment)->assertDontSee('Update to');
    });
});

describe('deploy anyway', function () {
    it('queues the same commit with the gate bypassed by the user', function () {
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);

        $component = surfacesShow($this->admin, $deployment)
            ->call('deployAnyway')
            ->assertDispatched('success', 'Deploy started.');

        $queued = ApplicationDeploymentQueue::query()->whereKeyNot($deployment->id)->sole();
        expect($queued->scan_gate_bypassed_by)->toBe($this->admin->id)
            ->and((bool) $queued->force_rebuild)->toBeFalse()
            ->and((bool) $queued->rollback)->toBeFalse()
            ->and($queued->commit)->toBe($deployment->commit)
            ->and($queued->pull_request_id)->toBe(0);
        $component->assertRedirect(route('project.application.deployment.show', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'application_uuid' => $this->application->uuid,
            'deployment_uuid' => $queued->deployment_uuid,
        ]));
    });

    it('queues on the server and destination of the blocked deployment and pins the blocked image', function () {
        $this->application->update(['build_pack' => 'dockerimage', 'docker_registry_image_name' => 'nginx', 'docker_registry_image_tag' => 'latest']);
        $additional = Server::factory()->create(['team_id' => $this->team->id]);
        $additionalDestination = StandaloneDocker::query()->where('server_id', $additional->id)->firstOrFail();
        $this->application->additional_servers()->attach($additional->id, ['standalone_docker_id' => $additionalDestination->id]);
        $digest = 'nginx@sha256:'.str_repeat('b', 64);
        $deployment = surfacesDeployment([
            'server_id' => $additional->id,
            'server_name' => $additional->name,
            'destination_id' => $additionalDestination->id,
            'only_this_server' => true,
            'scan_gate_result' => [...surfacesBlockedResult(), 'repo_digest' => $digest],
        ]);

        surfacesShow($this->admin, $deployment)->call('deployAnyway')->assertDispatched('success', 'Deploy started.');

        $queued = ApplicationDeploymentQueue::query()->whereKeyNot($deployment->id)->sole();
        expect($queued->server_id)->toBe($additional->id)
            ->and((int) $queued->destination_id)->toBe($additionalDestination->id)
            ->and((bool) $queued->only_this_server)->toBeTrue()
            ->and($queued->scan_gate_result)->toBe(['repo_digest' => $digest])
            ->and($queued->isImageScanBlocked())->toBeFalse()
            ->and($queued->isImageScanSkipped())->toBeFalse();
    });

    it('does not pin a built image or queue on a server that is not one of the application', function () {
        $otherServer = Server::factory()->create(['team_id' => Team::factory()->create()->id]);
        $deployment = surfacesDeployment([
            'server_id' => $otherServer->id,
            'scan_gate_result' => [...surfacesBlockedResult(), 'repo_digest' => 'app@sha256:'.str_repeat('b', 64)],
        ]);

        surfacesShow($this->admin, $deployment)->call('deployAnyway')->assertDispatched('success', 'Deploy started.');

        $queued = ApplicationDeploymentQueue::query()->whereKeyNot($deployment->id)->sole();
        expect($queued->server_id)->toBe($this->server->id)
            ->and($queued->scan_gate_result)->toBeNull();
    });

    it('refuses a deployment that was not blocked', function () {
        $deployment = surfacesDeployment();

        surfacesShow($this->admin, $deployment)
            ->call('deployAnyway')
            ->assertDispatched('error', 'This deployment was not blocked.');

        expect(ApplicationDeploymentQueue::query()->count())->toBe(1);
    });

    it('refuses a blocked deployment that is not the latest one', function () {
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        surfacesDeployment(['status' => ApplicationDeploymentStatus::FINISHED->value]);

        surfacesShow($this->admin, $deployment)
            ->call('deployAnyway')
            ->assertDispatched('error', 'A newer deployment exists.');

        expect(ApplicationDeploymentQueue::query()->count())->toBe(2);
    });

    it('forbids a user with a deploy-only grant', function () {
        $deployer = surfacesMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'deploy']],
        ]);
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);

        surfacesShow($deployer, $deployment)->call('deployAnyway')->assertForbidden();

        expect(ApplicationDeploymentQueue::query()->count())->toBe(1);
    });

    it('authorizes against the team of the application after the session switched teams', function () {
        $deployer = surfacesMember($this->team, 'member', [
            ['scope' => 'resource', 'uuid' => $this->application->uuid, 'levels' => ['read', 'deploy']],
        ]);
        $ownTeam = Team::factory()->create();
        $deployer->teams()->attach($ownTeam, ['role' => 'owner']);
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);

        $component = surfacesShow($deployer, $deployment);
        session(['currentTeam' => $ownTeam]);
        $component->call('deployAnyway')->assertForbidden();

        $writer = surfacesMember($this->team, 'admin');
        $writer->teams()->attach($ownTeam, ['role' => 'member']);
        $component = surfacesShow($writer, $deployment);
        session(['currentTeam' => $ownTeam]);
        $component->call('deployAnyway')->assertDispatched('success', 'Deploy started.');

        expect(ApplicationDeploymentQueue::query()->whereNotNull('scan_gate_bypassed_by')->sole()->scan_gate_bypassed_by)->toBe($writer->id);
    });
});

describe('topbar warning', function () {
    it('shows the gate status when the latest deployment was blocked', function () {
        $deployment = surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Heading::class, ['application' => $this->application])
            ->assertSeeHtml('data-image-scan-gate')
            ->assertSee('Deploy blocked: this image has a security problem that attackers are using.')
            ->assertSee($deployment->deployment_uuid);
    });

    it('hides the gate status when a newer deployment exists', function () {
        surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        surfacesDeployment(['status' => ApplicationDeploymentStatus::FINISHED->value]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Heading::class, ['application' => $this->application])
            ->assertDontSeeHtml('data-image-scan-gate');
    });

    it('reads no deployment when the instance does not allow scanning', function () {
        surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        InstanceSettings::query()->whereKey(0)->update(['is_image_scanning_allowed' => false]);
        Once::flush();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $component = Livewire::test(Heading::class, ['application' => $this->application]);

        DB::enableQueryLog();
        $gate = $component->instance()->imageScanGate();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($gate)->toBeNull()
            ->and($queries->filter(fn (string $query) => str_contains($query, 'application_deployment_queues')))->toBeEmpty();
        $component->assertDontSeeHtml('data-image-scan-gate');
    });

    it('reads no deployment when the app scan is off', function () {
        surfacesDeployment(['scan_gate_result' => surfacesBlockedResult()]);
        $this->application->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);
        $component = Livewire::test(Heading::class, ['application' => $this->application->fresh()]);

        DB::enableQueryLog();
        $gate = $component->instance()->imageScanGate();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($gate)->toBeNull()
            ->and($queries->filter(fn (string $query) => str_contains($query, 'application_deployment_queues')))->toBeEmpty();
        $component->assertDontSeeHtml('data-image-scan-gate');
    });

    it('ignores blocked preview deployments', function () {
        surfacesDeployment(['pull_request_id' => 7, 'scan_gate_result' => surfacesBlockedResult()]);
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(Heading::class, ['application' => $this->application])
            ->assertDontSeeHtml('data-image-scan-gate');
    });
});

describe('pull request comment', function () {
    it('says blocked with a link and no vulnerability IDs or counts', function () {
        $deployment = surfacesDeployment(['pull_request_id' => 7, 'scan_gate_result' => surfacesBlockedResult()]);

        $body = surfacesPullRequestBody($deployment);

        expect($body)->toContain('Image scan: blocked.')
            ->toContain('[Open in Coolify]('.base_url().'/project/'.$this->project->uuid.'/environment/'.$this->environment->uuid.'/application/'.$this->application->uuid.'/deployment/'.$deployment->deployment_uuid.')')
            ->not->toContain('CVE-')
            ->not->toContain('critical');
    });

    it('says passed when the scan ran and did not block', function () {
        $deployment = surfacesDeployment([
            'pull_request_id' => 7,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'scan_gate_result' => ['blocked' => false, 'scan_ran' => true, 'error' => null, 'count' => 0, 'headline' => '', 'top' => []],
        ]);

        expect(surfacesPullRequestBody($deployment))->toContain('Image scan: passed.');
    });

    it('has no image scan line when the gate did not run', function () {
        $deployment = surfacesDeployment(['pull_request_id' => 7]);

        expect(surfacesPullRequestBody($deployment))->not->toContain('Image scan');
    });

    it('has no image scan line when the scan could not run and the deploy went on', function () {
        $deployment = surfacesDeployment([
            'pull_request_id' => 7,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'scan_gate_result' => ['blocked' => false, 'scan_ran' => false, 'error' => 'timeout', 'count' => 0, 'headline' => 'Scan skipped: The scan took too long and was stopped.', 'top' => []],
        ]);

        expect(surfacesPullRequestBody($deployment))->not->toContain('Image scan');
    });
});

<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    InstanceSettings::unguarded(function () {
        InstanceSettings::query()->create([
            'id' => 0,
            'is_registration_enabled' => true,
        ]);
    });

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'status' => 'running',
    ]);
});

it('renders deployment logs in a full-height layout', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-layout-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => json_encode([
            [
                'command' => null,
                'output' => 'rolling update started',
                'type' => 'stdout',
                'timestamp' => now()->toISOString(),
                'hidden' => false,
                'batch' => 1,
                'order' => 1,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('rolling update started');
    $response->assertSee('logs-viewer', false);
    $response->assertSee('logs-viewer-toolbar', false);
    $response->assertSee('logs-viewer-search', false);
    $response->assertSee('logs-viewer-actions', false);
    $response->assertSee('logs-viewer-viewport', false);
    $response->assertSee('logs-viewer-line', false);
    $response->assertSee('min-h-[calc(100dvh-7.5rem)] flex-col', false);
    $response->assertSee('flex flex-1 min-h-0 flex-col overflow-hidden', false);
    $response->assertSee('h-[calc(100dvh-8rem)]', false);
    $response->assertSee('xl:h-[32rem]', false);
    $response->assertSee('xl:flex-none', false);

    expect($response->getContent())
        ->not->toContain('max-h-[30rem]')
        ->not->toContain('xl:h-[calc(100dvh-7.5rem)]')
        ->not->toContain('xl:overflow-hidden')
        ->not->toContain('h-[calc(100dvh-20rem)]')
        ->not->toContain('deployment-logs-panel');
});

it('uses a mobile-friendly stacked logs toolbar markup', function () {
    $deploymentView = file_get_contents(resource_path('views/livewire/project/application/deployment/show.blade.php'));
    $sharedLogsView = file_get_contents(resource_path('views/livewire/project/shared/get-logs.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($deploymentView)
        ->toContain('logs-viewer-toolbar')
        ->toContain('logs-viewer-toolbar-controls')
        ->toContain('logs-viewer-primary')
        ->toContain('logs-viewer-meta')
        ->toContain('logs-viewer-end')
        ->toContain('logs-viewer-status-badge')
        ->toContain('logs-viewer-line')
        ->toContain('logs-viewer-timestamp')
        ->toContain('logs-viewer-line-text')
        ->toContain('livewire:project.application.deployment-navbar')
        ->not->toContain('xl:h-[calc(100dvh-7.5rem)]')
        ->not->toContain('xl:overflow-hidden')
        ->toContain('xl:h-[32rem]')
        ->toContain('xl:flex-none')
        ->not->toContain('h-[calc(100dvh-20rem)]')
        ->and($sharedLogsView)
        ->toContain('logs-viewer-toolbar')
        ->toContain('logs-viewer-toolbar-controls')
        ->toContain('logs-viewer-meta')
        ->toContain('logs-viewer-lines')
        ->toContain('logs-viewer-actions')
        ->toContain('logs-viewer-line')
        ->toContain('pl-8!')
        ->toContain('z-10 size-3.5')
        ->and($deploymentView)
        ->toContain('pl-8!')
        ->toContain('z-10 size-3.5')
        ->and($appCss)
        ->toContain('.logs-viewer-toolbar')
        ->toContain('.logs-viewer-end')
        ->toContain('.logs-viewer-status-badge')
        ->toContain('.logs-viewer-lines')
        ->toContain('.logs-viewer-actions')
        ->toContain('.logs-viewer-deployment-actions')
        ->toContain('.logs-settings-section')
        ->toContain('padding: 0.5rem 0.75rem 0;')
        ->toContain('padding: 0.5rem 1rem 0;')
        ->toContain(".logs-viewer-viewport::after {\n    content: \"\";\n    flex: 0 0 2rem;")
        ->toContain('flex-direction: column')
        ->toContain('@media (min-width: 640px)');

    $actionsCss = str($appCss)
        ->after('.logs-viewer-actions {')
        ->before('}')
        ->toString();

    expect($actionsCss)->not->toContain('isolation: isolate');

    $primaryGroup = str($deploymentView)
        ->after('class="logs-viewer-primary"')
        ->before('class="logs-viewer-end"')
        ->toString();
    $endGroup = str($deploymentView)
        ->after('class="logs-viewer-end"')
        ->before('logs-viewer-viewport')
        ->toString();

    expect($primaryGroup)
        ->toContain('logs-viewer-status-badge')
        ->not->toContain('livewire:project.application.deployment-navbar');

    $timestampPos = strpos($primaryGroup, 'Toggle Timestamps');
    $followPos = strpos($primaryGroup, 'Follow Logs');
    $debugPos = strpos($primaryGroup, 'wire:click="toggleDebug"');
    $fullscreenPos = strpos($primaryGroup, 'title="Fullscreen"');
    $copyPos = strpos($primaryGroup, 'title="Copy Logs"');
    $downloadPos = strpos($primaryGroup, 'title="Download Logs"');
    $badgePos = strpos($primaryGroup, 'logs-viewer-status-badge');

    expect([$timestampPos, $followPos, $debugPos, $fullscreenPos, $copyPos, $downloadPos, $badgePos])
        ->not->toContain(false)
        ->and($timestampPos)->toBeLessThan($followPos)
        ->and($followPos)->toBeLessThan($debugPos)
        ->and($debugPos)->toBeLessThan($fullscreenPos)
        ->and($fullscreenPos)->toBeLessThan($copyPos)
        ->and($copyPos)->toBeLessThan($downloadPos)
        ->and($downloadPos)->toBeLessThan($badgePos)
        ->and($endGroup)->toContain('livewire:project.application.deployment-navbar')
        ->toContain('Find in logs');

    $runtimeActionsPos = strpos($sharedLogsView, 'class="logs-viewer-actions"');
    $runtimeEndPos = strpos($sharedLogsView, 'class="logs-viewer-end runtime-logs-viewer-end"');
    $runtimeLinesPos = strpos($sharedLogsView, 'class="logs-viewer-lines"');
    $runtimeSearchPos = strpos($sharedLogsView, 'placeholder="Find in logs"');

    expect([$runtimeActionsPos, $runtimeEndPos, $runtimeLinesPos, $runtimeSearchPos])
        ->not->toContain(false)
        ->and($runtimeActionsPos)->toBeLessThan($runtimeEndPos)
        ->and($runtimeEndPos)->toBeLessThan($runtimeLinesPos)
        ->and($runtimeLinesPos)->toBeLessThan($runtimeSearchPos)
        ->and($appCss)->toContain('.runtime-logs-viewer-end')
        ->toContain('.dark .runtime-log-icon-button-active')
        ->toContain('background: var(--color-coollabs)')
        ->toContain(".dark .runtime-log-icon-button {\n    color: var(--color-fg-faint);")
        ->toContain(".dark .runtime-log-icon-button-active {\n    background: var(--color-coollabs);\n    color: #fff;")
        ->toContain(".runtime-log-toolbar {\n    position: relative;\n    z-index: 20;")
        ->and($sharedLogsView)
        ->toContain('runtime-log-icon-button order-1')
        ->toContain('runtime-log-icon-button order-2')
        ->toContain('runtime-log-icon-button order-5')
        ->toContain('runtime-log-icon-button order-6')
        ->toContain('relative order-7 shrink-0');
});

it('keeps deployment history fields and the log status badge accessible on mobile', function () {
    $deploymentIndexView = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($deploymentIndexView)
        ->toContain('deployment-table-scroll')
        ->and($appCss)
        ->toContain(".deployment-table-scroll {\n    overflow-x: auto;")
        ->toContain(".deployment-table-grid {\n    min-width: 59rem;")
        ->toContain("@media (min-width: 1024px) {\n    .deployment-table-scroll {\n        overflow-x: visible;")
        ->toContain(".deployment-table-grid {\n        min-width: 0;")
        ->not->toContain('.deployment-table-grid > :nth-child')
        ->toContain(".logs-viewer-primary .logs-viewer-actions {\n    width: auto;\n    flex: 1 1 auto;");
});

it('links deployment commit hashes to the source commit page', function () {
    $githubApp = GithubApp::query()->create([
        'team_id' => $this->team->id,
        'name' => 'GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
    ]);
    $this->application->update([
        'source_id' => $githubApp->id,
        'source_type' => $githubApp->getMorphClass(),
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
    ]);
    ApplicationDeploymentQueue::query()->create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-commit-link-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => '1234567890abcdef1234567890abcdef12345678',
    ]);

    $response = $this->get(route('project.application.deployment.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee(
        'href="https://github.com/coollabsio/coolify/commit/1234567890abcdef1234567890abcdef12345678" target="_blank" rel="noopener noreferrer"',
        false,
    );
});

it('places cancel deployment controls inside the deployment logs toolbar', function () {
    $deployment = ApplicationDeploymentQueue::create([
        'application_id' => $this->application->id,
        'deployment_uuid' => 'deploy-cancel-toolbar-test',
        'server_id' => $this->server->id,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'logs' => json_encode([
            [
                'command' => null,
                'output' => 'building image',
                'type' => 'stdout',
                'timestamp' => now()->toISOString(),
                'hidden' => false,
                'batch' => 1,
                'order' => 1,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $response = $this->get(route('project.application.deployment.show', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'application_uuid' => $this->application->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
    ]));

    $response->assertSuccessful();
    $response->assertSee('Cancel deployment');
    $response->assertSee('logs-viewer-end', false);
    $response->assertSee('logs-viewer-deployment-actions', false);
    $response->assertSee('logs-viewer-cancel-btn', false);

    $content = $response->getContent();
    $toolbarPos = strpos($content, 'logs-viewer-toolbar');
    $endPos = strpos($content, 'logs-viewer-end');
    $viewportPos = strpos($content, 'logs-viewer-viewport');

    expect($toolbarPos)->not->toBeFalse()
        ->and($endPos)->not->toBeFalse()
        ->and($viewportPos)->not->toBeFalse()
        ->and($endPos)->toBeGreaterThan($toolbarPos)
        ->and($endPos)->toBeLessThan($viewportPos);

    // Within the right-side end group: cancel controls, then log search.
    $endGroup = substr($content, $endPos, $viewportPos - $endPos);
    $cancelPos = strpos($endGroup, 'Cancel deployment');
    $searchPos = strpos($endGroup, 'Find in logs');

    expect($cancelPos)->not->toBeFalse()
        ->and($searchPos)->not->toBeFalse()
        ->and($cancelPos)->toBeLessThan($searchPos)
        ->and($content)->toContain('In progress');
});

it('uses the shared mobile logs layout for proxy and sentinel log pages', function () {
    $proxyLogsView = file_get_contents(resource_path('views/livewire/server/proxy/logs.blade.php'));
    $sentinelLogsView = file_get_contents(resource_path('views/livewire/server/sentinel/logs.blade.php'));

    expect($proxyLogsView)
        ->toContain('logs-settings-section')
        ->toContain('logs-section-status-badge')
        ->toContain('livewire:project.shared.get-logs')
        ->and($sentinelLogsView)
        ->toContain('logs-settings-section')
        ->toContain('logs-section-status-badge')
        ->toContain('livewire:project.shared.get-logs');
});

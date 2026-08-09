<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Application\Deployment\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves deployment history pagination by one page per action', function () {
    expect((new ReflectionMethod(Index::class, 'nextPage'))->getNumberOfParameters())->toBe(0)
        ->and((new ReflectionMethod(Index::class, 'previousPage'))->getNumberOfParameters())->toBe(0);
});

it('filters deployment history by server', function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $firstServer = Server::factory()->create(['team_id' => $team->id, 'name' => 'Primary']);
    $secondServer = Server::factory()->create(['team_id' => $team->id, 'name' => 'Secondary']);
    $destination = StandaloneDocker::query()->where('server_id', $firstServer->id)->firstOrFail();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    foreach ([$firstServer, $secondServer] as $server) {
        ApplicationDeploymentQueue::query()->create([
            'application_id' => $application->id,
            'deployment_uuid' => "deployment-{$server->id}",
            'server_id' => $server->id,
            'server_name' => $server->name,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
        ]);
    }

    ApplicationDeploymentQueue::query()->create([
        'application_id' => $application->id,
        'deployment_uuid' => 'webhook-deployment',
        'server_id' => $secondServer->id,
        'server_name' => $secondServer->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'is_webhook' => true,
    ]);

    $result = $application->deployments(filters: [
        'status:finished',
        'source:manual',
        'source:webhook',
        "server:{$secondServer->id}",
    ]);

    expect($result['count'])->toBe(2)
        ->and($result['deployments']->pluck('server_id')->unique()->sole())->toBe($secondServer->id);
});

it('always shows source filters and includes server filters', function () {
    $component = file_get_contents(app_path('Livewire/Project/Application/Deployment/Index.php'));
    $view = file_get_contents(resource_path('views/livewire/project/application/deployment/index.blade.php'));
    $filterComponent = file_get_contents(resource_path('views/components/table/filter.blade.php'));
    $loadingComponent = file_get_contents(resource_path('views/components/table/loading.blade.php'));

    expect($component)
        ->toContain('public array $serverFilterOptions = [];')
        ->toContain('public array $deploymentFilters = [];')
        ->toContain('public function toggleDeploymentFilter(string $filter): void')
        ->toContain("'value' => \"server:{\$serverId}\"")
        ->and($view)
        ->toContain('@if (count($sourceFilterOptions) > 0)')
        ->toContain('count($serverFilterOptions) > 0')
        ->toContain('>Server</span>')
        ->toContain('<x-table.filter')
        ->toContain("wire:click=\"toggleDeploymentFilter('{{ \$option['value'] }}')\"")
        ->toContain("in_array(\$option['value'], \$deploymentFilters, true)")
        ->toContain('<x-table.loading id="deployment-table-filter-loading"')
        ->not->toContain('class="size-3.5" wire:loading.remove')
        ->not->toContain('<span>All deployments</span>')
        ->and($filterComponent)
        ->toContain('aria-multiselectable="true"')
        ->toContain('Reset filters')
        ->and($loadingComponent)
        ->toContain('wire:loading.flex');
});

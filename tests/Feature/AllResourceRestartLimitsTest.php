<?php

use App\Models\ApplicationPreview;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Traits\HasRestartLimit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

it('gives every independently runnable non-application resource restart limit state', function (string $modelClass) {
    expect(class_uses_recursive($modelClass))->toContain(HasRestartLimit::class);

    $resource = new $modelClass;

    expect($resource->getFillable())->toContain(
        'restart_count',
        'max_restart_count',
        'restart_limit_reached',
        'last_restart_at',
        'last_restart_type',
    )->and($resource->getCasts())->toMatchArray([
        'restart_count' => 'integer',
        'max_restart_count' => 'integer',
        'restart_limit_reached' => 'boolean',
        'last_restart_at' => 'datetime',
    ]);
})->with([
    ApplicationPreview::class,
    ServiceApplication::class,
    ServiceDatabase::class,
    StandaloneClickhouse::class,
    StandaloneDragonfly::class,
    StandaloneKeydb::class,
    StandaloneMariadb::class,
    StandaloneMongodb::class,
    StandaloneMysql::class,
    StandalonePostgresql::class,
    StandaloneRedis::class,
]);

it('collects restart counts for preview and service containers from both status sources', function () {
    $dockerStatus = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));
    $sentinelStatus = file_get_contents(app_path('Jobs/PushServerUpdateJob.php'));

    expect($dockerStatus)
        ->toContain('previewContainerRestartCounts')
        ->toContain('serviceContainerRestartCounts')
        ->and($sentinelStatus)
        ->toContain('previewContainerRestartCounts')
        ->toContain('serviceContainerRestartCounts');
});

it('ignores docker compose one-off job containers in both status sources', function () {
    $dockerStatus = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));
    $sentinelStatus = file_get_contents(app_path('Jobs/PushServerUpdateJob.php'));

    expect($dockerStatus)
        ->toContain("filter_var(data_get(\$labels, 'com.docker.compose.oneoff'), FILTER_VALIDATE_BOOLEAN)")
        ->and($sentinelStatus)
        ->toContain("filter_var(\$labels->get('com.docker.compose.oneoff'), FILTER_VALIDATE_BOOLEAN)");
});

it('shows restart limit warnings for every resource family', function () {
    $previews = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));
    $serviceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));
    $applicationStatus = file_get_contents(resource_path('views/livewire/project/application/status.blade.php'));
    $databaseStatus = file_get_contents(resource_path('views/livewire/project/database/status.blade.php'));

    expect($previews)->toContain('<x-application.restart-limit-warning :application="$preview" />')
        ->and($serviceCard)->toContain('<x-application.restart-limit-warning :application="$resource" />')
        ->and($applicationStatus)->toContain('<x-application.restart-limit-warning :application="$application" />')
        ->and($databaseStatus)->toContain('<x-application.restart-limit-warning :application="$database" />');

    $serviceStatus = file_get_contents(resource_path('views/livewire/project/service/status.blade.php'));
    $serviceHeading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    expect($serviceStatus)->toContain('<x-application.restart-limit-warning :application="$selectedResource" />')
        ->and($serviceStatus)->toContain('$selectedResource?->status ?? $service->status')
        ->and($serviceHeading)->toContain('<x-application.restart-limit-warning :application="$selectedResource" />')
        ->and($serviceHeading)->toContain('$selectedResource?->status ?? $service->status');
});

it('matches application restart badge layout on mobile resource headings', function () {
    $applicationHeading = file_get_contents(resource_path('views/livewire/project/application/heading.blade.php'));
    $databaseHeading = file_get_contents(resource_path('views/livewire/project/database/heading.blade.php'));
    $serviceHeading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));

    foreach ([$applicationHeading, $databaseHeading, $serviceHeading] as $heading) {
        expect($heading)
            ->toContain('class="relative flex w-full min-w-0 items-center gap-2"')
            ->toContain('class="flex w-full flex-wrap gap-1"');
    }
});

it('shows database restart limits in shared resource listings', function () {
    $resourceIndex = file_get_contents(app_path('Livewire/Project/Resource/Index.php'));
    $serverResources = file_get_contents(resource_path('views/livewire/server/resources.blade.php'));
    $destination = file_get_contents(resource_path('views/livewire/project/shared/destination.blade.php'));

    expect($resourceIndex)
        ->toContain("method_exists(\$item, 'stoppedAfterRestartLimit')")
        ->not->toContain("\$type === 'application' && \$item->stoppedAfterRestartLimit()")
        ->and($serverResources)
        ->toContain('<x-application.restart-limit-warning :application="$resource" />')
        ->and($destination)
        ->toContain('<x-application.restart-limit-warning :application="$resource" />');
});

it('keeps the service resource table readable with horizontal scrolling on mobile', function () {
    $configuration = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));

    expect($configuration)
        ->toContain("'overflow-x-auto rounded-xl")
        ->toContain('min-w-[48rem]')
        ->and($resourceCard)
        ->toContain('min-w-[48rem]')
        ->not->toContain('<div class="hidden truncate font-mono');
});

it('opens service resource settings when a table row is clicked', function () {
    $resourceCard = file_get_contents(resource_path('views/livewire/project/service/resource-card.blade.php'));

    expect($resourceCard)
        ->toContain('x-on:click="openSettings($event)"')
        ->toContain('x-on:keydown.enter="openSettings($event)"')
        ->toContain("closest('a, button')")
        ->toContain('role="link"')
        ->toContain('tabindex="0"');
});

it('uses selected service resource actions instead of parent complex status actions', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $headingClass = file_get_contents(app_path('Livewire/Project/Service/Heading.php'));

    expect(substr_count($heading, "\$selectedResource && \$selectedResource->container_present !== false && \$selectedResourceStatus->startsWith('exited')"))->toBe(2)
        ->and($heading)
        ->toContain('Remove container')
        ->toContain('removeSelectedResourceContainer')
        ->and($headingClass)
        ->toContain('public function removeSelectedResourceContainer(): void');
});

it('imports the application model used when claiming a restart limit', function () {
    $statusAction = file_get_contents(app_path('Actions/Docker/GetContainersStatus.php'));

    expect($statusAction)
        ->toContain('use App\\Models\\Application;')
        ->toContain('Application::query()');
});

it('adds restart limit columns to previews services and standalone databases', function () {
    $migrations = collect(glob(database_path('migrations/*.php')))
        ->map(fn (string $path): string => file_get_contents($path))
        ->implode("\n");

    expect($migrations)
        ->toContain("'application_previews'")
        ->toContain("'service_applications'")
        ->toContain("'service_databases'")
        ->toContain("'max_restart_count'")
        ->toContain("'restart_limit_reached'");

    $restartLimitMigrations = collect(glob(database_path('migrations/*_add_restart_limit_to_*.php')));

    expect($restartLimitMigrations)->toHaveCount(11);
    expect($restartLimitMigrations->map(
        fn (string $path): string => substr(basename($path), 0, 17)
    )->unique())->toHaveCount(11);
    $restartLimitMigrations->each(function (string $path): void {
        expect(file_get_contents($path))->not->toContain('foreach (');
    });
});

it('atomically claims a resource restart limit once and can reset it', function () {
    Schema::create('restart_limit_test_resources', function (Blueprint $table): void {
        $table->id();
        $table->string('status')->default('running');
        $table->integer('restart_count')->default(0);
        $table->integer('max_restart_count')->default(2);
        $table->boolean('restart_limit_reached')->default(false);
        $table->timestamp('last_restart_at')->nullable();
        $table->string('last_restart_type')->nullable();
        $table->timestamps();
    });

    $resource = new class extends Model
    {
        use HasRestartLimit;

        protected $table = 'restart_limit_test_resources';
    };
    $resource->save();
    $resource->refresh();

    expect($resource->trackRestartCount(2))->toBeTrue()
        ->and($resource->fresh()->restart_limit_reached)->toBeTrue()
        ->and($resource->trackRestartCount(2))->toBeFalse();

    $resource->resetRestartLimit();

    expect($resource->fresh()->restart_count)->toBe(0)
        ->and($resource->restart_limit_reached)->toBeFalse();

    $resourceWithExistingRestarts = $resource->newInstance();
    $resourceWithExistingRestarts->max_restart_count = 0;
    $resourceWithExistingRestarts->save();
    expect($resourceWithExistingRestarts->trackRestartCount(17))->toBeFalse();

    $resourceWithExistingRestarts->update(['max_restart_count' => 10]);
    expect($resourceWithExistingRestarts->trackRestartCount(17))->toBeTrue()
        ->and($resourceWithExistingRestarts->fresh()->restart_limit_reached)->toBeTrue();

    Schema::drop('restart_limit_test_resources');
});

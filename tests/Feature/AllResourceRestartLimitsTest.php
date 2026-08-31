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

    Schema::drop('restart_limit_test_resources');
});

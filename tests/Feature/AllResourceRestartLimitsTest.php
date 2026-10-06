<?php

use App\Models\Application;
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
use App\Models\StandaloneSqlite;
use App\Traits\HasRestartLimit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;

it('gives independently runnable application resources restart limit state', function (string $modelClass) {
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
    [ApplicationPreview::class],
    [ServiceApplication::class],
]);

it('limits restarts only for applications', function () {
    $databaseModels = [
        ServiceDatabase::class,
        StandalonePostgresql::class,
        StandaloneRedis::class,
        StandaloneMongodb::class,
        StandaloneMysql::class,
        StandaloneMariadb::class,
        StandaloneKeydb::class,
        StandaloneDragonfly::class,
        StandaloneClickhouse::class,
        StandaloneSqlite::class,
    ];

    foreach ($databaseModels as $databaseModel) {
        expect(class_uses_recursive($databaseModel))->not->toContain(HasRestartLimit::class);
    }

    foreach ([
        'service_databases',
        'standalone_postgresqls',
        'standalone_redis',
        'standalone_mongodbs',
        'standalone_mysqls',
        'standalone_mariadbs',
        'standalone_keydbs',
        'standalone_dragonflies',
        'standalone_clickhouses',
        'standalone_sqlites',
    ] as $databaseTable) {
        expect(Schema::hasColumn($databaseTable, 'max_restart_count'))->toBeFalse()
            ->and(Schema::hasColumn($databaseTable, 'restart_limit_reached'))->toBeFalse();
    }
});

it('makes restart limits opt in for new application resources', function () {
    foreach ([Application::class, ApplicationPreview::class, ServiceApplication::class] as $modelClass) {
        expect((new $modelClass)->max_restart_count)->toBe(0);
    }
});

it('does not render restart limit warnings for service databases', function () {
    $html = Blade::render(
        '<x-application.restart-limit-warning :application="$database" />',
        ['database' => new ServiceDatabase],
    );

    expect(trim($html))->toBeEmpty();
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

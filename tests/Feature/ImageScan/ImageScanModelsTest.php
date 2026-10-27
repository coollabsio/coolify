<?php

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\ImageScanPolicy;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

function imageScanTestApplication(): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

it('seeds the instance policy row', function () {
    $policy = ImageScanPolicy::query()->whereNull('scope_type')->first();

    expect($policy)->not->toBeNull()
        ->and($policy->gate_mode)->toBe(ImageScanGateMode::Off)
        ->and($policy->on_scan_error)->toBe(ImageScanOnError::Deploy)
        ->and($policy->uuid)->not->toBeEmpty();
});

it('resolves the scope of a policy', function () {
    $policy = ImageScanPolicy::factory()->create([
        'scope_type' => $this->server->getMorphClass(),
        'scope_id' => $this->server->id,
        'gate_mode' => ImageScanGateMode::Strict,
    ]);

    expect($policy->fresh()->scope->is($this->server))->toBeTrue()
        ->and($policy->fresh()->gate_mode)->toBe(ImageScanGateMode::Strict)
        ->and($policy->fresh()->on_scan_error)->toBeNull();
});

it('deletes scans with their application', function () {
    $app = imageScanTestApplication();
    ImageScan::factory()->for($app, 'resource')->create(['server_id' => $this->server->id]);
    expect($app->imageScans()->count())->toBe(1);

    $app->delete();

    expect(ImageScan::count())->toBe(0);
});

it('deletes scans with a standalone database', function () {
    $database = StandalonePostgresql::forceCreate([
        'name' => 'pg',
        'postgres_password' => 'secret',
        'image' => 'postgres:16',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    ImageScan::factory()->for($database, 'resource')->create(['server_id' => $this->server->id]);

    $database->delete();

    expect(ImageScan::count())->toBe(0);
});

it('keeps the scans of other resources when one is deleted', function () {
    $first = imageScanTestApplication();
    $second = imageScanTestApplication();
    ImageScan::factory()->for($first, 'resource')->create(['server_id' => $this->server->id]);
    ImageScan::factory()->for($second, 'resource')->create(['server_id' => $this->server->id]);

    $first->delete();

    expect(ImageScan::count())->toBe(1)
        ->and(ImageScan::first()->resource->is($second))->toBeTrue();
});

it('casts scan columns', function () {
    $scan = ImageScan::factory()->for(imageScanTestApplication(), 'resource')->create([
        'server_id' => $this->server->id,
        'status' => ImageScanStatus::Failed,
        'error_code' => ImageScanErrorCode::Timeout,
        'summary' => ['total' => 2],
        'findings' => [['id' => 'CVE-2024-1']],
        'builds_image' => true,
        'scanned_at' => now(),
    ])->fresh();

    expect($scan->status)->toBe(ImageScanStatus::Failed)
        ->and($scan->error_code)->toBe(ImageScanErrorCode::Timeout)
        ->and($scan->summary)->toBe(['total' => 2])
        ->and($scan->findings)->toBe([['id' => 'CVE-2024-1']])
        ->and($scan->builds_image)->toBeTrue()
        ->and($scan->is_baseline)->toBeTrue()
        ->and($scan->scanned_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($scan->server->is($this->server))->toBeTrue()
        ->and($scan->uuid)->not->toBeEmpty();
});

it('links a scan to its sbom and the sbom to the server', function () {
    $sbom = ImageSbom::factory()->create(['server_id' => $this->server->id, 'packages' => [['name' => 'openssl']]]);
    $scan = ImageScan::factory()->for(imageScanTestApplication(), 'resource')->create([
        'server_id' => $this->server->id,
        'image_sbom_id' => $sbom->id,
    ]);

    expect($scan->sbom->is($sbom))->toBeTrue()
        ->and($sbom->fresh()->packages)->toBe([['name' => 'openssl']])
        ->and($sbom->fresh()->packages_truncated)->toBeFalse()
        ->and($this->server->imageSboms()->count())->toBe(1)
        ->and($this->server->imageScans()->count())->toBe(1);

    $sbom->delete();
    expect($scan->fresh()->image_sbom_id)->toBeNull();
});

it('treats expired ignores as inactive', function () {
    ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'expires_at' => now()->subDay()]);
    ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'expires_at' => now()->addDay()]);
    ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'expires_at' => null]);

    expect(ImageScanIgnore::active()->count())->toBe(2);
});

it('scopes an ignore to a resource or to the whole team', function () {
    $app = imageScanTestApplication();
    $own = ImageScanIgnore::factory()->for($app, 'resource')->create(['team_id' => $this->team->id]);
    $all = ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => null, 'resource_id' => null]);

    expect($own->fresh()->resource->is($app))->toBeTrue()
        ->and($own->team->is($this->team))->toBeTrue()
        ->and($all->fresh()->resource)->toBeNull();
});

it('defaults server scanning to off', function () {
    expect(Server::factory()->create(['team_id' => $this->team->id])->settings->is_image_scan_enabled)->toBeFalse();
});

it('defaults the other scan settings', function () {
    expect(InstanceSettings::findOrFail(0)->is_image_scanning_allowed)->toBeTrue()
        ->and(Team::factory()->create()->scan_images_on_new_servers)->toBeFalse()
        ->and(imageScanTestApplication()->settings->is_image_scan_enabled)->toBeTrue()
        ->and(ApplicationSetting::query()->first()->fresh()->image_builds_by_team)->toBeNull();
});

it('writes the candidate scan counter', function () {
    $settings = $this->server->settings;
    $settings->forceFill(['image_candidate_scans_today' => 3, 'image_candidate_scans_date' => '2026-10-26'])->saveQuietly();

    expect($settings->fresh()->image_candidate_scans_today)->toBe(3)
        ->and($settings->fresh()->image_candidate_scans_date->toDateString())->toBe('2026-10-26');
});

it('describes the gate modes and the scan error options', function () {
    expect(ImageScanGateMode::Off->label())->toBe('Off')
        ->and(ImageScanGateMode::On->label())->toBe('On')
        ->and(ImageScanGateMode::Strict->label())->toBe('Strict')
        ->and(ImageScanGateMode::Off->description())->toBeNull()
        ->and(ImageScanGateMode::On->description())->toBe('Blocks a deploy when the new image adds a problem that attackers are using or are likely to use.')
        ->and(ImageScanGateMode::Strict->description())->toBe('Also blocks critical and high problems that have a fix, and operating systems that no longer get security fixes.')
        ->and(ImageScanOnError::Deploy->label())->toBe('Deploy anyway')
        ->and(ImageScanOnError::Block->label())->toBe('Block the deploy');
});

it('builds error messages from their context', function () {
    expect(ImageScanErrorCode::DiskSpace->message(['server' => 'web-1', 'n' => 4]))
        ->toBe('Not enough disk space on web-1: 4 GB needed.')
        ->and(ImageScanErrorCode::DbTooOld->message(['server' => 'web-1', 'n' => 6]))
        ->toBe('The vulnerability database is 6 days old. web-1 cannot reach grype.anchore.io.')
        ->and(ImageScanErrorCode::ScanningOff->message(['server' => 'web-1']))
        ->toBe('Turn on image scanning on web-1.');

    foreach (ImageScanErrorCode::cases() as $code) {
        expect($code->message([]))->toBeString()->not->toBeEmpty()->not->toContain('{');
    }
});

it('deletes the team rules and the ignores of a team with the team', function () {
    $other = Team::factory()->create();
    ImageScanPolicy::factory()->create(['scope_type' => $this->team->getMorphClass(), 'scope_id' => $this->team->id, 'gate_mode' => ImageScanGateMode::On]);
    $kept = ImageScanPolicy::factory()->create(['scope_type' => $other->getMorphClass(), 'scope_id' => $other->id, 'gate_mode' => ImageScanGateMode::On]);
    ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'resource_type' => null, 'resource_id' => null]);
    $otherIgnore = ImageScanIgnore::factory()->create(['team_id' => $other->id, 'resource_type' => null, 'resource_id' => null]);

    $this->team->deleteOwnedRecords();

    expect(ImageScanPolicy::query()->where('scope_type', $this->team->getMorphClass())->pluck('id')->all())->toBe([$kept->id])
        ->and(ImageScanIgnore::query()->pluck('id')->all())->toBe([$otherIgnore->id]);
});

it('keeps one index on the resource of a notification state, the unique key', function () {
    $indexes = collect(Schema::getIndexes('image_scan_notification_states'))
        ->filter(fn (array $index) => $index['columns'] === ['resource_type', 'resource_id']);

    expect($indexes)->toHaveCount(1)
        ->and($indexes->first()['unique'])->toBeTrue();
});

it('turns Block deploys on into strict and drops the alert level when it migrates', function () {
    $migration = require database_path('migrations/2026_10_27_130000_replace_image_scan_gate_enabled_with_gate_mode.php');
    $migration->down();
    ImageScanPolicy::query()->whereNull('scope_type')->delete();
    $rows = [
        'instance' => [null, null, null],
        'on' => [$this->server->getMorphClass(), $this->server->id, true],
        'off' => [$this->team->getMorphClass(), $this->team->id, false],
        'inherit' => ['App\\Models\\Application', 1, null],
    ];
    foreach ($rows as $name => [$type, $id, $gate]) {
        DB::table('image_scan_policies')->insert(['uuid' => $name, 'scope_type' => $type, 'scope_id' => $id, 'alert_level' => 'high', 'gate_enabled' => $gate]);
    }

    $migration->up();

    expect(DB::table('image_scan_policies')->pluck('gate_mode', 'uuid')->all())->toBe([
        'instance' => 'off',
        'on' => 'strict',
        'off' => 'off',
        'inherit' => null,
    ])
        ->and(Schema::hasColumns('image_scan_policies', ['alert_level']))->toBeFalse()
        ->and(Schema::hasColumns('image_scan_policies', ['gate_enabled']))->toBeFalse();
});

<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Enums\SecurityStatusType;
use App\Jobs\RefreshImageScanSummariesJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\ImageScanIgnore;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ImageScan\ResourceScans;
use App\Services\ImageScan\ResourceSecurity;
use App\Services\ImageScan\SecuritySentences;
use App\Services\ImageScan\SecurityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Cache::flush();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);
    Queue::fake();

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->server->settings->forceFill(['is_image_scan_enabled' => true])->saveQuietly();
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = statusTestApplication();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function statusTestApplication(array $attributes = []): Application
{
    return Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
        'build_pack' => 'dockerimage',
        'docker_registry_image_name' => 'nginx',
        'docker_registry_image_tag' => '1.27',
        ...$attributes,
    ]);
}

/**
 * A relevant high finding with a package fix and no exploit signal.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function statusTestFinding(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'aliases' => [],
        'severity' => 'high',
        'package' => 'openssl',
        'version' => '3.0.1',
        'type' => 'apk',
        'artifactId' => 'a-'.$id,
        'fixedIn' => '3.0.2',
        'fixState' => 'fixed',
        'kev' => false,
        'epss' => null,
        'risk' => 1.0,
        'summary' => '',
        'layer' => 'app',
        'title' => 'Problem '.$id,
        ...$overrides,
    ];
}

/**
 * @param  array<string, list<string>>  $fixedByRef  Candidate ref => fixed IDs. A ref with the current tag is a same_tag candidate.
 * @return array<string, mixed>
 */
function statusTestCandidates(array $fixedByRef = [], string $status = 'ok'): array
{
    return [
        'status' => $status,
        'checked_at' => now()->toIso8601String(),
        'error' => null,
        'tags_truncated' => false,
        'entries' => collect($fixedByRef)->map(fn (array $ids, string $ref): array => [
            'kind' => str_ends_with($ref, ':1.27') || str_ends_with($ref, ':1.0') ? 'same_tag' : 'line',
            'ref' => $ref,
            'better' => true,
            'fixed_ids' => $ids,
            'eol' => null,
        ])->values()->all(),
    ];
}

/**
 * @param  list<array<string, mixed>>  $findings
 * @param  array<string, mixed>  $attributes
 */
function statusTestScan(Model $resource, array $findings, array $attributes = []): ImageScan
{
    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $resource->getMorphClass(),
        'resource_id' => $resource->getKey(),
        'image_ref' => 'nginx:1.27',
        'builds_image' => false,
        'findings' => $findings,
        'candidates' => statusTestCandidates(status: 'no_better'),
        'summary' => ['truncated' => false, 'eol_distro' => null],
        'grype_db_built_at' => now()->subDay(),
        'scanned_at' => now(),
        ...$attributes,
    ]);
}

function statusTestService(): Service
{
    return Service::factory()->create([
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
}

function statusTestServiceApplication(Service $service, string $name, string $image): ServiceApplication
{
    return ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'service_id' => $service->id, 'name' => $name, 'image' => $image]);
}

describe('status rules', function () {
    it('is not checked without a completed scan', function () {
        statusTestScan($this->application, [], ['status' => ImageScanStatus::Failed]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::NotChecked)
            ->and($security->checkReason)->toBe('not_scanned')
            ->and(SecurityStatus::stored($this->application)->status)->toBe(SecurityStatusType::NotChecked);
    });

    it('is not checked when scanning is off for the server', function () {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['kev' => true])]);
        $this->server->settings->forceFill(['is_image_scan_enabled' => false])->saveQuietly();

        $security = SecurityStatus::forResource($this->application->fresh());

        expect($security->status)->toBe(SecurityStatusType::NotChecked)
            ->and($security->checkReason)->toBe('server');
    });

    it('needs an update now when a finding that attackers are using has an action', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['kev' => true]),
            statusTestFinding('CVE-2024-0002', ['kev' => true, 'package' => 'curl']),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001']])]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::UpdateNow)
            ->and($security->checkReason)->toBeNull()
            ->and($security->actions[0]->type)->toBe(SecurityActionType::UpdateImage)
            ->and($security->actions[0]->target)->toBe('1.28')
            ->and($security->problemCount)->toBe(1)
            ->and($security->exploitedCount)->toBe(2)
            ->and($security->anyExploited)->toBeTrue()
            ->and($security->fixedByPrimary)->toBe(1)
            ->and($security->exploitedWithoutFix)->toBeTrue();
    });

    it('says likely when no fixed finding is in the known exploited list', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['epss' => 0.4, 'cvssScore' => 8.1]),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001']])]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::UpdateNow)
            ->and($security->anyExploited)->toBeFalse()
            ->and($security->exploitedWithoutFix)->toBeFalse();
    });

    it('has a security update when a serious finding without an exploit signal has an action', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['severity' => 'critical']),
            statusTestFinding('CVE-2024-0002'),
            statusTestFinding('CVE-2024-0003', ['package' => 'curl']),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001', 'CVE-2024-0002']])]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::UpdateAvailable)
            ->and($security->problemCount)->toBe(2)
            ->and($security->criticalCount)->toBe(1)
            ->and($security->highCount)->toBe(1)
            ->and($security->fixedByPrimary)->toBe(2)
            ->and($security->exploitedWithoutFix)->toBeFalse();
    });

    it('flags a security update when a finding that attackers are using has no fix', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001'),
            statusTestFinding('CVE-2024-0002', ['kev' => true, 'package' => 'curl']),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001']])]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::UpdateAvailable)
            ->and($security->exploitedWithoutFix)->toBeTrue()
            ->and($security->exploitedCount)->toBe(1);
    });

    it('has a security update when an action leaves an end-of-life OS', function () {
        statusTestScan($this->application, [], [
            'summary' => ['truncated' => false, 'eol_distro' => ['name' => 'alpine', 'version' => '3.16']],
            'candidates' => statusTestCandidates(['nginx:1.28' => []]),
        ]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::UpdateAvailable)
            ->and($security->eol)->toMatchArray(['name' => 'alpine', 'version' => '3.16', 'fixed' => true])
            ->and($security->actions[0]->type)->toBe(SecurityActionType::UpdateImage)
            ->and($security->problemCount)->toBe(1)
            ->and($security->criticalCount)->toBe(1)
            ->and($security->fixedByPrimary)->toBe(1)
            ->and($security->primaryCritical)->toBe(1)
            ->and($security->why)->toBe([])
            ->and($security->moreFixed)->toBe(0);
    });

    it('counts leaving an end-of-life OS as one critical fix of the action', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001'),
            statusTestFinding('CVE-2024-0002', ['package' => 'curl']),
            statusTestFinding('CVE-2024-0003', ['package' => 'zlib', 'severity' => 'critical']),
        ], [
            'summary' => ['truncated' => false, 'eol_distro' => ['name' => 'alpine', 'version' => '3.16']],
            'candidates' => statusTestCandidates([
                'nginx:1.28' => ['CVE-2024-0001'],
                'nginx:1.27' => ['CVE-2024-0002', 'CVE-2024-0003'],
            ]),
        ]);

        $security = SecurityStatus::forResource($this->application);

        // 1 high + the end-of-life OS ties with 1 high + 1 critical; update_image comes first in the type order.
        expect($security->actions[0]->type)->toBe(SecurityActionType::UpdateImage)
            ->and($security->problemCount)->toBe(4)
            ->and($security->criticalCount)->toBe(2)
            ->and($security->highCount)->toBe(2)
            ->and($security->fixedByPrimary)->toBe(2)
            ->and($security->primaryCritical)->toBe(1)
            ->and($security->primaryHigh)->toBe(1)
            ->and($security->moreFixed)->toBe(0);
    });

    it('has no fix when a finding that attackers are using has no action', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['kev' => true, 'cvssScore' => 9.8]),
            statusTestFinding('CVE-2024-0002'),
        ]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::NoFix)
            ->and($security->actions)->toBe([])
            ->and($security->problemCount)->toBe(1)
            ->and($security->anyExploited)->toBeTrue()
            ->and($security->exploitedWithoutFix)->toBeFalse()
            ->and(array_column($security->why, 'display_id'))->toBe(['CVE-2024-0001']);
    });

    it('knows that a builder update fixes every problem without a fix', function () {
        $builder = statusTestApplication(['build_pack' => 'nixpacks']);
        statusTestScan($builder, [statusTestFinding('CVE-2024-0001', ['kev' => true])], ['builds_image' => true]);
        $dockerfile = statusTestApplication(['build_pack' => 'dockerfile']);
        statusTestScan($dockerfile, [statusTestFinding('CVE-2024-0001', ['kev' => true])], ['builds_image' => true]);

        $builderSecurity = SecurityStatus::forResource($builder);
        ResourceScans::refreshSummaries(ResourceScans::currentFor($builder));

        expect($builderSecurity->status)->toBe(SecurityStatusType::NoFix)
            ->and($builderSecurity->builderImage)->toBeTrue()
            ->and(SecurityStatus::stored($builder)->builderImage)->toBeTrue()
            ->and(ResourceSecurity::fromArray($builderSecurity->toArray())->builderImage)->toBeTrue()
            ->and(SecuritySentences::headline($builderSecurity))->toContain('A fix exists, but it reaches this app only with a builder update')
            ->and(SecurityStatus::forResource($dockerfile)->builderImage)->toBeFalse()
            ->and(SecurityStatus::forResource($this->application)->builderImage)->toBeFalse();
    });

    it('does not promise a builder fix for a builder image without a fixed version', function () {
        $builder = statusTestApplication(['build_pack' => 'nixpacks']);
        statusTestScan($builder, [statusTestFinding('CVE-2024-0001', ['kev' => true, 'fixedIn' => null, 'fixState' => 'not-fixed'])], ['builds_image' => true]);

        $security = SecurityStatus::forResource($builder);

        expect($security->status)->toBe(SecurityStatusType::NoFix)
            ->and($security->builderImage)->toBeFalse()
            ->and(SecuritySentences::headline($security))->toContain('no fixed version exists yet')
            ->and(SecuritySentences::headline($security))->not->toContain('A fix exists');
    });

    it('does not promise a builder fix for a language package of a builder image', function () {
        $builder = statusTestApplication(['build_pack' => 'nixpacks']);
        statusTestScan($builder, [
            statusTestFinding('CVE-2024-0001', ['kev' => true]),
            statusTestFinding('CVE-2024-0002', ['kev' => true, 'type' => 'binary', 'package' => 'tool']),
        ], ['builds_image' => true]);

        expect(SecurityStatus::forResource($builder)->builderImage)->toBeFalse();
    });

    it('does not call a service no_fix a builder one when another image drives it', function () {
        $service = statusTestService();
        $web = statusTestServiceApplication($service, 'web', 'nginx:1.27');
        $worker = statusTestServiceApplication($service, 'worker', 'nginx:1.27');
        statusTestScan($web, [statusTestFinding('CVE-2024-0001', ['kev' => true])]);
        statusTestScan($worker, []);

        $security = SecurityStatus::forResource($service);

        expect($security->status)->toBe(SecurityStatusType::NoFix)
            ->and($security->builderImage)->toBeFalse();
    });

    it('says whether a problem without a fix is a known exploited one', function () {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['epss' => 0.4, 'cvssScore' => 8.1])]);

        $likely = SecurityStatus::forResource($this->application);

        expect($likely->status)->toBe(SecurityStatusType::NoFix)
            ->and($likely->anyExploited)->toBeFalse()
            ->and(SecuritySentences::headline($likely))->toStartWith('This image has a security problem that attackers are likely to use, and no fixed version exists yet.');

        $other = statusTestApplication();
        statusTestScan($other, [statusTestFinding('CVE-2024-0001', ['epss' => 0.4, 'cvssScore' => 8.1]), statusTestFinding('CVE-2024-0002', ['kev' => true])]);

        expect(SecuritySentences::headline(SecurityStatus::forResource($other)))
            ->toStartWith('This image has 2 security problems that attackers are using, and no fixed version exists yet.');
    });

    it('is ok when no relevant finding has an exploit signal or an action', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001'),
            statusTestFinding('CVE-2024-0002', ['severity' => 'medium', 'epss' => 0.9]),
        ]);

        expect(SecurityStatus::forResource($this->application)->status)->toBe(SecurityStatusType::Ok);
    });

    it('leaves ignored findings and CPE-only OS matches out', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['kev' => true]),
            statusTestFinding('CVE-2024-0002', ['kev' => true, 'namespace' => 'nvd:cpe']),
        ]);
        ImageScanIgnore::query()->forceCreate(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2024-0001', 'reason' => 'not used', 'expires_at' => now()->addDays(30)]);

        expect(SecurityStatus::forResource($this->application)->status)->toBe(SecurityStatusType::Ok);
    });

    it('needs a check before it decides update now', function () {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['kev' => true])], [
            'candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001']]),
            'scanned_at' => now()->subDays(3),
            'grype_db_built_at' => now()->subDays(3),
        ]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::CheckNeeded)
            ->and($security->checkReason)->toBe('stale')
            ->and($security->actions[0]->type)->toBe(SecurityActionType::UpdateImage);
    });
});

describe('check needed reasons', function () {
    it('needs a check when an image of a service is not checked', function () {
        $service = statusTestService();
        $web = statusTestServiceApplication($service, 'web', 'nginx:1.27');
        statusTestServiceApplication($service, 'worker', 'nginx:1.27');
        statusTestScan($web, [statusTestFinding('CVE-2024-0001')]);

        $security = SecurityStatus::forResource($service);

        expect($security->status)->toBe(SecurityStatusType::CheckNeeded)
            ->and($security->checkReason)->toBe('images_unchecked')
            ->and($security->checkContext)->toBe(['checked' => 1, 'total' => 2]);
    });

    it('counts only the images of a service that Coolify scans', function () {
        $service = statusTestService();
        $web = statusTestServiceApplication($service, 'web', 'nginx:1.27');
        statusTestServiceApplication($service, 'sidecar', 'busybox:1')->forceFill(['exclude_from_status' => true])->save();
        statusTestServiceApplication($service, 'empty', '');
        statusTestScan($web, []);
        ResourceScans::refreshSummaries(ResourceScans::currentFor($web));

        expect(SecurityStatus::forResource($service)->status)->toBe(SecurityStatusType::Ok)
            ->and(SecurityStatus::stored($service->fresh())->status)->toBe(SecurityStatusType::Ok);
    });

    it('needs a check when an image of the current Compose deployment has no completed result', function (string $missing) {
        $application = statusTestApplication(['build_pack' => 'dockercompose']);
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => Str::random(12),
            'application_id' => $application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'pull_request_id' => 0,
        ]);
        $application->forceFill(['docker_compose' => Yaml::dump(['services' => [
            'web' => ['image' => 'nginx:1.27'],
            'db' => ['image' => 'postgres:16'],
            'cache' => ['image' => '${CACHE_IMAGE:-redis:7}'],
        ]])])->save();
        statusTestScan($application, [], ['deployment_queue_id' => $deployment->id]);
        if ($missing === 'failed row') {
            statusTestScan($application, [], ['deployment_queue_id' => $deployment->id, 'image_ref' => 'postgres:16', 'status' => ImageScanStatus::Failed]);
        }
        ResourceScans::refreshSummaries(ResourceScans::currentFor($application));

        foreach ([SecurityStatus::forResource($application), SecurityStatus::stored($application)] as $security) {
            expect($security->status)->toBe(SecurityStatusType::CheckNeeded)
                ->and($security->checkReason)->toBe('images_unchecked')
                ->and($security->checkContext)->toBe(['checked' => 1, 'total' => 2]);
        }
    })->with(['failed row', 'image without a row']);

    it('treats a Compose deployment with a result for every image as checked', function () {
        $application = statusTestApplication(['build_pack' => 'dockercompose']);
        $application->forceFill(['docker_compose' => Yaml::dump(['services' => ['web' => ['image' => 'nginx:1.27']]])])->save();
        statusTestScan($application, []);

        expect(SecurityStatus::forResource($application)->status)->toBe(SecurityStatusType::Ok);
    });

    it('needs a check when the newest result is older than 2 days', function () {
        $scannedAt = now()->subDays(2)->subHour();
        statusTestScan($this->application, [], ['scanned_at' => $scannedAt, 'grype_db_built_at' => $scannedAt]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->status)->toBe(SecurityStatusType::CheckNeeded)
            ->and($security->checkReason)->toBe('stale')
            ->and($security->checkContext['time'])->toBe($scannedAt->toIso8601String())
            ->and($security->checkedAt?->toIso8601String())->toBe($scannedAt->toIso8601String());
    });

    it('needs a check when the vulnerability database was more than 5 days old', function () {
        statusTestScan($this->application, [], ['grype_db_built_at' => now()->subDays(5)->subHour()]);

        expect(SecurityStatus::forResource($this->application))
            ->status->toBe(SecurityStatusType::CheckNeeded)
            ->checkReason->toBe('db_outdated');
    });

    it('needs a check when the running image is not the scanned image', function () {
        statusTestScan($this->application, []);
        $this->application->update(['docker_registry_image_tag' => '1.28']);

        expect(SecurityStatus::forResource($this->application->fresh()))
            ->status->toBe(SecurityStatusType::CheckNeeded)
            ->checkReason->toBe('image_changed');
    });

    it('needs a check while the version check failed or did not run and relevant findings exist', function (?array $candidates) {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['kev' => true])], ['candidates' => $candidates]);

        expect(SecurityStatus::forResource($this->application))
            ->status->toBe(SecurityStatusType::CheckNeeded)
            ->checkReason->toBe('versions_unchecked');
    })->with([
        'not run' => [null],
        'failed' => [['status' => 'failed', 'error' => 'unknown', 'entries' => []]],
        'auth required' => [['status' => 'auth_required', 'error' => null, 'entries' => []]],
        'busy' => [['status' => 'failed', 'error' => 'lock_busy', 'entries' => []]],
    ]);

    it('counts versions that cannot be compared as checked', function (string $status) {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['kev' => true])], ['candidates' => statusTestCandidates(status: $status)]);

        expect(SecurityStatus::forResource($this->application)->status)->toBe(SecurityStatusType::NoFix);
    })->with(['cannot_compare', 'no_better']);

    it('needs no version check without relevant findings', function () {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['severity' => 'medium'])], ['candidates' => null]);

        expect(SecurityStatus::forResource($this->application)->status)->toBe(SecurityStatusType::Ok);
    });

    it('needs a check while the base origin of a Dockerfile image is unknown', function () {
        $application = statusTestApplication(['build_pack' => 'dockerfile']);
        statusTestScan($application, [statusTestFinding('CVE-2024-0001')], [
            'image_ref' => $application->uuid.':latest',
            'builds_image' => true,
            'base_image_ref' => 'node:20-alpine',
            'candidates' => ['status' => 'skipped', 'entries' => [], 'base' => ['image' => 'node:20-alpine', 'status' => 'failed', 'attributable' => null, 'attributable_ids' => [], 'entries' => []]],
        ]);

        expect(SecurityStatus::forResource($application))
            ->status->toBe(SecurityStatusType::CheckNeeded)
            ->checkReason->toBe('versions_unchecked');
    });

    it('decides a Dockerfile image once the base origin is known', function () {
        $application = statusTestApplication(['build_pack' => 'dockerfile']);
        statusTestScan($application, [statusTestFinding('CVE-2024-0001')], [
            'image_ref' => $application->uuid.':latest',
            'builds_image' => true,
            'base_image_ref' => 'node:20-alpine',
            'candidates' => ['status' => 'skipped', 'entries' => [], 'base' => ['image' => 'node:20-alpine', 'status' => 'no_better', 'attributable' => 0, 'attributable_ids' => [], 'entries' => []]],
        ]);

        $security = SecurityStatus::forResource($application);

        expect($security->status)->toBe(SecurityStatusType::UpdateAvailable)
            ->and($security->actions[0]->type)->toBe(SecurityActionType::Rebuild);
    });
});

describe('primary action and why-list', function () {
    it('picks the action that fixes the most findings with an exploit signal', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['severity' => 'critical']),
            statusTestFinding('CVE-2024-0002', ['severity' => 'critical', 'package' => 'curl']),
            statusTestFinding('CVE-2024-0003', ['kev' => true, 'package' => 'zlib']),
        ], ['candidates' => statusTestCandidates([
            'nginx:1.28' => ['CVE-2024-0001', 'CVE-2024-0002'],
            'nginx:1.27' => ['CVE-2024-0003'],
        ])]);

        $security = SecurityStatus::forResource($this->application);

        expect(array_map(fn ($action) => $action->type, $security->actions))->toBe([SecurityActionType::PullLatest, SecurityActionType::UpdateImage])
            ->and($security->fixedByPrimary)->toBe(1)
            ->and($security->problemCount)->toBe(1);
    });

    it('picks the action that fixes the most critical and high findings next', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001'),
            statusTestFinding('CVE-2024-0002', ['package' => 'curl']),
            statusTestFinding('CVE-2024-0003', ['package' => 'zlib']),
        ], ['candidates' => statusTestCandidates([
            'nginx:1.28' => ['CVE-2024-0001'],
            'nginx:1.27' => ['CVE-2024-0002', 'CVE-2024-0003'],
        ])]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->actions[0]->type)->toBe(SecurityActionType::PullLatest)
            ->and($security->fixedByPrimary)->toBe(2)
            ->and($security->problemCount)->toBe(3);
    });

    it('lists up to 3 fixed findings: exploited, likely, then by score', function () {
        $ids = ['CVE-2024-0001', 'CVE-2024-0002', 'CVE-2024-0003', 'GHSA-abcd-efgh-ijkl', 'CVE-2024-0005'];
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['cvssScore' => 9.8]),
            statusTestFinding('CVE-2024-0002', ['epss' => 0.5, 'cvssScore' => 7.5, 'package' => 'curl']),
            statusTestFinding('CVE-2024-0003', ['kev' => true, 'severity' => 'medium', 'cvssScore' => 5.0, 'package' => 'zlib']),
            statusTestFinding('GHSA-abcd-efgh-ijkl', ['severity' => 'critical', 'cvssScore' => 9.9, 'package' => 'lodash', 'type' => 'npm']),
            statusTestFinding('CVE-2024-0005', ['severity' => 'critical', 'package' => 'musl']),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => $ids])]);

        $security = SecurityStatus::forResource($this->application);

        expect($security->why)->toBe([
            ['title' => 'Problem CVE-2024-0003', 'display_id' => 'CVE-2024-0003', 'link' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-0003', 'score' => 5.0, 'severity' => 'medium', 'signal' => 'exploited'],
            ['title' => 'Problem CVE-2024-0002', 'display_id' => 'CVE-2024-0002', 'link' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-0002', 'score' => 7.5, 'severity' => 'high', 'signal' => 'likely'],
            ['title' => 'Problem GHSA-abcd-efgh-ijkl', 'display_id' => 'GHSA-abcd-efgh-ijkl', 'link' => 'https://github.com/advisories/GHSA-abcd-efgh-ijkl', 'score' => 9.9, 'severity' => 'critical', 'signal' => null],
        ])->and($security->moreFixed)->toBe(2);
    });

    it('links no ID that does not pass the ID pattern', function () {
        statusTestScan($this->application, [
            statusTestFinding('ALPINE-1 <x>', ['kev' => true]),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['ALPINE-1 <x>']])]);

        expect(SecurityStatus::forResource($this->application)->why[0]['link'])->toBeNull();
    });

    it('merges the updates of service images that run one tag and update to one tag into one action', function () {
        $service = statusTestService();
        $main = statusTestServiceApplication($service, 'n8n', 'n8nio/n8n:1.0');
        $runners = statusTestServiceApplication($service, 'n8n runners', 'n8nio/runners:1.0');
        $cache = statusTestServiceApplication($service, 'cache', 'redis:7.0');
        foreach ([[$main, 'n8nio/n8n:1.1'], [$runners, 'n8nio/runners:1.1'], [$cache, 'redis:1.1']] as [$owner, $candidate]) {
            statusTestScan($owner, [statusTestFinding('CVE-2024-0001', ['kev' => true])], [
                'image_ref' => $owner->image,
                'candidates' => statusTestCandidates([$candidate => ['CVE-2024-0001']]),
            ]);
        }

        $security = SecurityStatus::forResource($service);

        expect($security->status)->toBe(SecurityStatusType::UpdateNow)
            ->and(array_map(fn ($action) => $action->sentence, $security->actions))->toBe(['Update n8n and n8n runners to 1.1.', 'Update to 1.1.'])
            ->and($security->actions[0]->fixedIds)->toBe(['CVE-2024-0001'])
            ->and($security->actions[0]->parts)->toBe([
                ['owner_type' => $main->getMorphClass(), 'owner_id' => $main->id, 'image' => 'n8nio/n8n:1.0', 'ref' => 'n8nio/n8n:1.1'],
                ['owner_type' => $runners->getMorphClass(), 'owner_id' => $runners->id, 'image' => 'n8nio/runners:1.0', 'ref' => 'n8nio/runners:1.1'],
            ])
            ->and($security->actions[1]->parts)->toBe([])
            ->and($security->actions[1]->image)->toBe('redis:7.0')
            ->and($security->problemCount)->toBe(3)
            ->and($security->fixedByPrimary)->toBe(2);

        ResourceScans::refreshSummaries(ResourceScans::current($service));
        expect(SecurityStatus::stored($service->fresh())->actions[0]->parts)->toBe($security->actions[0]->parts);
    });

    it('gives Coolify its status but no buttons', function () {
        $coolify = statusTestApplication(['id' => 0]);
        statusTestScan($coolify, [statusTestFinding('CVE-2024-0001', ['kev' => true])], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001']])]);

        $security = SecurityStatus::forResource($coolify);

        expect($security->status)->toBe(SecurityStatusType::UpdateNow)
            ->and($security->actions[0]->button)->toBeFalse()
            ->and($security->actions[0]->sentence)->toBe('Update Coolify to get a newer version.');
    });
});

describe('stored status', function () {
    it('counts an old row without the stored status as not checked', function () {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['kev' => true])]);

        expect(SecurityStatus::stored($this->application))
            ->status->toBe(SecurityStatusType::NotChecked)
            ->checkReason->toBe('not_scanned');
    });

    it('stores the status of each scan with the summaries and reads it back like the resource page', function () {
        $scan = statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['kev' => true]),
            statusTestFinding('CVE-2024-0002', ['package' => 'curl']),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001', 'CVE-2024-0002']])]);

        ResourceScans::refreshSummaries(ResourceScans::currentFor($this->application));

        $stored = SecurityStatus::stored($this->application);
        $live = SecurityStatus::forResource($this->application);
        expect($scan->fresh()->summary['security']['status'])->toBe('update_now')
            ->and($scan->fresh()->summary)->not->toHaveKey('fixable_now_at_level')
            ->and($stored->toArray())->toBe($live->toArray())
            ->and($stored->actions[0]->ref)->toBe('nginx:1.28');
    });

    it('applies the age of the newest result when it reads the stored status', function () {
        statusTestScan($this->application, [], ['scanned_at' => now()]);
        ResourceScans::refreshSummaries(ResourceScans::currentFor($this->application));

        $this->travel(3)->days();

        expect(SecurityStatus::stored($this->application))
            ->status->toBe(SecurityStatusType::CheckNeeded)
            ->checkReason->toBe('stale');
    });

    it('needs a check for a service with an image that has no stored status', function () {
        $service = statusTestService();
        $web = statusTestServiceApplication($service, 'web', 'nginx:1.27');
        $worker = statusTestServiceApplication($service, 'worker', 'nginx:1.27');
        statusTestScan($web, []);
        statusTestScan($worker, []);
        ResourceScans::refreshSummaries(ResourceScans::currentFor($web));

        expect(SecurityStatus::stored($service))
            ->status->toBe(SecurityStatusType::CheckNeeded)
            ->checkReason->toBe('images_unchecked')
            ->checkContext->toBe(['checked' => 1, 'total' => 2]);
    });

    it('stores no status for a preview scan', function () {
        $deployment = ApplicationDeploymentQueue::create([
            'deployment_uuid' => Str::random(12),
            'application_id' => $this->application->id,
            'server_id' => $this->server->id,
            'status' => ApplicationDeploymentStatus::FINISHED->value,
            'pull_request_id' => 3,
        ]);
        $scan = statusTestScan($this->application, [], ['deployment_queue_id' => $deployment->id]);
        $scan->setRelation('resource', $this->application);

        ResourceScans::refreshSummaries(collect([$scan]));

        expect($scan->fresh()->summary)->not->toHaveKey('security');
    });

    it('keeps the action data with its action when a stored action cannot be read', function () {
        statusTestScan($this->application, [
            statusTestFinding('CVE-2024-0001', ['kev' => true]),
            statusTestFinding('CVE-2024-0002', ['package' => 'curl']),
        ], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0002'], 'nginx:1.27' => ['CVE-2024-0001']])]);
        $data = SecurityStatus::forResource($this->application)->toArray();
        $data['actions'][0]['type'] = 'unknown';

        $security = ResourceSecurity::fromArray($data);

        expect($security->actions)->toHaveCount(1)
            ->and($security->actions[0]->type)->toBe(SecurityActionType::UpdateImage)
            ->and($security->facts['action_stats'])->toHaveCount(1)
            ->and($security->facts['action_stats'][0]['high'])->toBe(1);
    });

    it('round-trips through the array form', function () {
        statusTestScan($this->application, [statusTestFinding('CVE-2024-0001', ['kev' => true])], ['candidates' => statusTestCandidates(['nginx:1.28' => ['CVE-2024-0001']])]);
        $security = SecurityStatus::forResource($this->application);

        expect(ResourceSecurity::fromArray($security->toArray())->toArray())->toBe($security->toArray());
    });

    it('refreshes the stored status of every team once after the upgrade', function () {
        statusTestScan($this->application, []);
        $migration = require database_path('migrations/2026_10_27_120000_refresh_image_scan_security_status.php');

        $migration->up();

        Queue::assertPushed(RefreshImageScanSummariesJob::class, fn (RefreshImageScanSummariesJob $job): bool => $job->teamId === $this->team->id);
        Queue::assertPushed(RefreshImageScanSummariesJob::class, 1);
    });
});

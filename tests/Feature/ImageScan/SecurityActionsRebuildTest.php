<?php

use App\Actions\ImageScan\RefreshCandidates;
use App\Actions\ImageScan\ScanImage;
use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ImageScanStatus;
use App\Enums\SecurityActionType;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\ImageScan;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ImageScan\IgnoreMatcher;
use App\Services\ImageScan\SecurityActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Storage::fake('ssh-keys');
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);

    $team = Team::factory()->create();
    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->newApplication = fn (string $buildPack = 'dockerfile'): Application => Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => $buildPack,
    ]);
    $this->application = ($this->newApplication)();
});

/**
 * @param  list<string>  $ids
 */
function rebuildLoopScan(array $ids, bool $forceRebuild, int $pullRequestId = 0, ?Application $application = null): ImageScan
{
    $application ??= test()->application;
    $deployment = ApplicationDeploymentQueue::create([
        'deployment_uuid' => Str::random(12),
        'application_id' => $application->id,
        'server_id' => test()->server->id,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'commit' => 'abc123',
        'pull_request_id' => $pullRequestId,
        'force_rebuild' => $forceRebuild,
    ]);

    return ImageScan::factory()->create([
        'server_id' => test()->server->id,
        'resource_type' => $application->getMorphClass(),
        'resource_id' => $application->id,
        'deployment_queue_id' => $deployment->id,
        'image_ref' => $application->uuid.':abc123',
        'builds_image' => true,
        'findings' => array_map(fn (string $id): array => [
            'id' => $id, 'aliases' => [], 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13',
            'type' => 'apk', 'artifactId' => 'a-'.$id, 'fixedIn' => '3.0.15', 'fixState' => 'fixed', 'kev' => false,
        ], $ids),
    ]);
}

function rebuildLoopAction(ImageScan $scan, string $id): ?SecurityActionType
{
    return SecurityActions::forScan($scan->fresh(), IgnoreMatcher::fromIgnores(collect()))['byFinding'][$id]?->type ?? null;
}

it('switches to an upgrade in the Dockerfile when a finding survives a rebuild without cache', function () {
    rebuildLoopScan(['CVE-1'], forceRebuild: false);
    $scan = rebuildLoopScan(['CVE-1', 'CVE-2'], forceRebuild: true);

    $result = SecurityActions::forScan($scan->fresh(), IgnoreMatcher::fromIgnores(collect()));

    expect($result['byFinding']['CVE-1']->type)->toBe(SecurityActionType::UpgradeInDockerfile)
        ->and($result['byFinding']['CVE-1']->sentence)->toBe('Upgrade openssl to 3.0.15 or later in your Dockerfile.')
        ->and($result['byFinding']['CVE-1']->button)->toBeFalse()
        ->and($result['byFinding']['CVE-2']->type)->toBe(SecurityActionType::Rebuild);
});

it('keeps the rebuild when the deployment did not rebuild without cache', function () {
    rebuildLoopScan(['CVE-1'], forceRebuild: false);
    $scan = rebuildLoopScan(['CVE-1'], forceRebuild: false);

    expect(rebuildLoopAction($scan, 'CVE-1'))->toBe(SecurityActionType::Rebuild);
});

it('keeps the rebuild after the first rebuild without cache of an app', function () {
    $scan = rebuildLoopScan(['CVE-1'], forceRebuild: true);

    expect(rebuildLoopAction($scan, 'CVE-1'))->toBe(SecurityActionType::Rebuild);
});

it('compares with the previous scan that is not a preview and completed', function () {
    rebuildLoopScan(['CVE-9'], forceRebuild: false);
    rebuildLoopScan(['CVE-1'], forceRebuild: false, pullRequestId: 4);
    rebuildLoopScan(['CVE-1'], forceRebuild: false)->update(['status' => ImageScanStatus::Failed]);
    $scan = rebuildLoopScan(['CVE-1'], forceRebuild: true);

    expect(rebuildLoopAction($scan, 'CVE-1'))->toBe(SecurityActionType::Rebuild);
});

it('does not compare with the scans of another application', function () {
    rebuildLoopScan(['CVE-1'], forceRebuild: false, application: ($this->newApplication)());
    $scan = rebuildLoopScan(['CVE-1'], forceRebuild: true);

    expect(rebuildLoopAction($scan, 'CVE-1'))->toBe(SecurityActionType::Rebuild);
});

it('looks for newer versions of an image a compose app pulls, but not of one it builds', function () {
    $application = ($this->newApplication)('dockercompose');
    $application->forceFill(['docker_compose' => Yaml::dump(['services' => [
        'web' => ['build' => '.', 'image' => $application->uuid.'_web:abc123'],
        'db' => ['image' => 'postgres:16'],
    ]], 10)])->save();
    $scan = fn (string $imageRef): ImageScan => ImageScan::factory()->create([
        'server_id' => $this->server->id,
        'resource_type' => $application->getMorphClass(),
        'resource_id' => $application->id,
        'image_ref' => $imageRef,
        'builds_image' => ScanImage::buildsImage($application, $imageRef),
        'findings' => [['id' => 'CVE-1', 'severity' => 'critical', 'package' => 'openssl', 'version' => '3.0.13', 'type' => 'deb', 'fixedIn' => '3.0.15', 'fixState' => 'fixed']],
    ]);

    expect(RefreshCandidates::needsCandidates($scan('postgres:16')))->toBeTrue()
        ->and(RefreshCandidates::needsCandidates($scan($application->uuid.'_web:abc123')))->toBeFalse();
});

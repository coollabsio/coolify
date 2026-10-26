<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageScanIgnore;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Services\ImageScan\Finding;
use App\Services\ImageScan\IgnoreMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = ignoreMatcherApplication($this->environment, $this->destination);
    $this->finding = Finding::fromArray([
        'id' => 'GHSA-jfh8-c2jp-5v3q', 'aliases' => ['CVE-2021-44228'], 'package' => 'log4j-core',
    ]);
});

function ignoreMatcherApplication(Environment $environment, StandaloneDocker $destination): Application
{
    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

it('loads a resource ignore and a team-wide ignore of the resource team', function () {
    $own = ImageScanIgnore::factory()->create([
        'team_id' => $this->team->id,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
        'vulnerability_id' => 'CVE-2021-44228',
    ]);
    $wide = ImageScanIgnore::factory()->create([
        'team_id' => $this->team->id,
        'vulnerability_id' => null,
        'package' => 'log4j-core',
    ]);

    $matcher = IgnoreMatcher::forResource($this->application);

    expect($matcher->matches($this->finding)->is($own))->toBeTrue()
        ->and($matcher->matches(Finding::fromArray(['id' => 'CVE-2020-1', 'package' => 'log4j-core']))->is($wide))->toBeTrue();
});

it('never loads an ignore of another team', function () {
    $otherTeam = Team::factory()->create();
    ImageScanIgnore::factory()->create(['team_id' => $otherTeam->id, 'vulnerability_id' => 'CVE-2021-44228']);
    ImageScanIgnore::factory()->create([
        'team_id' => $otherTeam->id,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
        'vulnerability_id' => 'CVE-2021-44228',
    ]);

    expect(IgnoreMatcher::forResource($this->application)->matches($this->finding))->toBeNull();
});

it('does not load an ignore that belongs to another resource', function () {
    $other = ignoreMatcherApplication($this->environment, $this->destination);
    ImageScanIgnore::factory()->create([
        'team_id' => $this->team->id,
        'resource_type' => $other->getMorphClass(),
        'resource_id' => $other->id,
        'vulnerability_id' => 'CVE-2021-44228',
    ]);

    expect(IgnoreMatcher::forResource($this->application)->matches($this->finding))->toBeNull()
        ->and(IgnoreMatcher::forResource($other)->matches($this->finding))->not->toBeNull();
});

it('does not load an expired ignore', function () {
    ImageScanIgnore::factory()->create([
        'team_id' => $this->team->id,
        'vulnerability_id' => 'CVE-2021-44228',
        'expires_at' => now()->subMinute(),
    ]);

    expect(IgnoreMatcher::forResource($this->application)->matches($this->finding))->toBeNull();
});

it('matches nothing for a resource without a team', function () {
    ImageScanIgnore::factory()->create(['team_id' => $this->team->id, 'vulnerability_id' => 'CVE-2021-44228']);
    $orphan = new Application;

    expect(IgnoreMatcher::forResource($orphan)->matches($this->finding))->toBeNull();
});

it('never matches an expired ignore passed in directly', function () {
    $expired = new ImageScanIgnore(['vulnerability_id' => 'CVE-2021-44228', 'reason' => 'x', 'expires_at' => now()->subMinute()]);
    $active = new ImageScanIgnore(['vulnerability_id' => 'CVE-2021-44228', 'reason' => 'x', 'expires_at' => now()->addDay()]);

    expect(IgnoreMatcher::fromIgnores(collect([$expired]))->matches($this->finding))->toBeNull()
        ->and(IgnoreMatcher::fromIgnores(collect([$expired, $active]))->matches($this->finding))->toBe($active);
});

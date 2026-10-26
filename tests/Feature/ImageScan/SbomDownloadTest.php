<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\ImageSbom;
use App\Models\ImageScan;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    Cache::flush();
    Server::flushIdentityMap();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_image_scanning_allowed' => true]);

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->member->teams()->attach($this->team, ['role' => 'member']);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $this->environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->sbom = ImageSbom::factory()->create([
        'server_id' => $this->server->id,
        'packages' => [
            // A row with extra keys, as a leak of image config into the stored list would look: they must not be copied.
            ['id' => 'p1', 'name' => 'openssl', 'version' => '3.0.1', 'type' => 'apk', 'licenses' => ['Apache-2.0'], 'purl' => 'pkg:apk/alpine/openssl@3.0.1', 'layerId' => 'sha256:aa',
                'Env' => ['SECRET_TOKEN=leaked'], 'history' => [['created_by' => 'RUN echo leaked']]],
            ['id' => 'p2', 'name' => 'left-pad', 'version' => '1.3.0', 'type' => 'npm', 'licenses' => ['MIT or something'], 'purl' => '', 'layerId' => 'sha256:bb'],
        ],
    ]);
    $this->scan = ImageScan::factory()->create([
        'server_id' => $this->server->id,
        'image_sbom_id' => $this->sbom->id,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
        'image_ref' => 'nginx:1.27',
        'findings' => [],
        'summary' => ['fixable_now_at_level' => 0],
    ]);
});

function sbomDownloadUrl(string $format, ?ImageScan $scan = null): string
{
    return route('project.application.vulnerabilities.sbom', [
        'project_uuid' => test()->environment->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'application_uuid' => test()->application->uuid,
        'scan_uuid' => ($scan ?? test()->scan)->uuid,
        'format' => $format,
    ]);
}

it('downloads a CycloneDX SBOM made from the package list only', function () {
    $response = $this->actingAs($this->member)
        ->withSession(['currentTeam' => $this->team])
        ->get(sbomDownloadUrl('cyclonedx'));

    $response->assertOk()
        ->assertHeader('Content-Disposition')
        ->assertJsonPath('bomFormat', 'CycloneDX')
        ->assertJsonPath('specVersion', '1.6')
        ->assertJsonPath('components.0.name', 'openssl')
        ->assertJsonPath('components.0.purl', 'pkg:apk/alpine/openssl@3.0.1');

    $document = $response->json();
    expect(array_keys($document))->toEqualCanonicalizing(['bomFormat', 'specVersion', 'serialNumber', 'version', 'metadata', 'components'])
        ->and(array_keys($document['metadata']))->toEqualCanonicalizing(['timestamp', 'tools', 'component'])
        ->and($response->getContent())->not->toContain('Env')
        ->and($response->getContent())->not->toContain('history')
        ->and($response->getContent())->not->toContain('leaked');
    foreach ($document['components'] as $component) {
        expect(array_diff(array_keys($component), ['type', 'bom-ref', 'name', 'version', 'purl', 'licenses']))->toBe([]);
    }
});

it('downloads an SPDX SBOM with license expressions only for plain identifiers', function () {
    $response = $this->actingAs($this->member)
        ->withSession(['currentTeam' => $this->team])
        ->get(sbomDownloadUrl('spdx'));

    $response->assertOk()
        ->assertJsonPath('spdxVersion', 'SPDX-2.3')
        ->assertJsonPath('packages.0.licenseDeclared', 'Apache-2.0')
        ->assertJsonPath('packages.1.licenseDeclared', 'NOASSERTION')
        ->assertJsonCount(2, 'relationships');

    expect($response->getContent())->not->toContain('leaked');
});

it('returns not found for the resource of another team', function () {
    $stranger = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $stranger->teams()->attach($otherTeam, ['role' => 'owner']);

    $this->actingAs($stranger)
        ->withSession(['currentTeam' => $otherTeam])
        ->get(sbomDownloadUrl('cyclonedx'))
        ->assertNotFound();
});

it('returns not found for a scan that is not current', function () {
    $older = ImageScan::factory()->create([
        'server_id' => $this->server->id,
        'image_sbom_id' => $this->sbom->id,
        'resource_type' => $this->application->getMorphClass(),
        'resource_id' => $this->application->id,
        'scanned_at' => now()->subDay(),
    ]);

    $this->actingAs($this->member)
        ->withSession(['currentTeam' => $this->team])
        ->get(sbomDownloadUrl('cyclonedx', $older))
        ->assertNotFound();
});

it('returns not found for an unknown format', function () {
    $this->actingAs($this->member)
        ->withSession(['currentTeam' => $this->team])
        ->get(sbomDownloadUrl('xml'))
        ->assertNotFound();
});

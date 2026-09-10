<?php

use App\Jobs\ServerFilesFromServerJob;
use App\Livewire\Project\Application\PreviewDomains;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Symfony\Component\Yaml\Exception\ParseException;

uses(RefreshDatabase::class);

const SERVICE_NAMES_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:latest
  worker-pr-5:
    image: nginx:latest
  db:
    image: postgres:16
YAML;

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($this->user->id, ['role' => 'owner']);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => SERVICE_NAMES_COMPOSE,
    ]);
    $this->actingAs($this->user);
    session(['currentTeam' => $team]);
});

it('keeps original service keys for current parsers and skips database images', function () {
    $this->application->update(['compose_parsing_version' => '3']);

    expect($this->application->composeServiceNamesForPreview(5))->toBe(['web', 'worker-pr-5']);
});

it('returns original compose keys even when the legacy parser suffixes them', function () {
    $this->application->update(['compose_parsing_version' => '2']);

    // The legacy parser suffixes every key; listing preview names still returns the original keys.
    expect(array_keys(data_get($this->application->parse(5), 'services')))->toBe(['web-pr-5', 'worker-pr-5-pr-5', 'db-pr-5'])
        ->and($this->application->composeServiceNamesForPreview(5))->toBe(['web', 'worker-pr-5']);
});

it('does not persist parser side effects when listing preview service names', function (string $version) {
    $compose = <<<'YAML'
services:
  web:
    image: nginx:latest
    volumes:
      - data:/var/www
      - ./config.txt:/app/config.txt
  worker-pr-5:
    image: nginx:latest
  db:
    image: postgres:16
volumes:
  data:
YAML;

    $this->application->update([
        'compose_parsing_version' => $version,
        'docker_compose_raw' => $compose,
        'docker_compose' => null,
    ]);

    expect($this->application->composeServiceNamesForPreview(5))->toBe(['web', 'worker-pr-5']);

    $fresh = $this->application->fresh();
    expect($fresh->docker_compose)->toBeNull()
        ->and($fresh->docker_compose_raw)->toBe($compose);
    expect(LocalPersistentVolume::query()->count())->toBe(0);
    Bus::assertNotDispatched(ServerFilesFromServerJob::class);
})->with([
    'current parser' => '3',
    'legacy parser' => '2',
]);

it('is shared by the preview model and the preview domains component', function () {
    $this->application->update(['compose_parsing_version' => '3']);
    $preview = ApplicationPreview::create([
        'application_id' => $this->application->id,
        'pull_request_id' => 5,
        'pull_request_html_url' => 'https://github.com/example/repository/pull/5',
    ]);

    $names = $this->application->composeServiceNamesForPreview(5);
    $component = Livewire::test(PreviewDomains::class, ['preview' => $preview]);

    expect($component->get('newDomainService'))->toBe($names[0]);
    $component->assertSee('worker-pr-5');
});

it('throws on an unparsable compose file so callers do not treat it as no services', function () {
    $this->application->update(['docker_compose_raw' => "services: [unclosed\n  web:\n"]);

    expect(fn () => $this->application->composeServiceNamesForPreview(5))->toThrow(ParseException::class);
});

it('returns no names for an empty compose file', function () {
    $this->application->update(['docker_compose_raw' => null]);

    expect($this->application->composeServiceNamesForPreview(5))->toBe([]);
});

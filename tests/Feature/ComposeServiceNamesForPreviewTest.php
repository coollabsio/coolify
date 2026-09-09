<?php

use App\Livewire\Project\Application\PreviewDomains;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

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

it('strips the preview suffix only for legacy parsers', function () {
    $this->application->update(['compose_parsing_version' => '2']);

    // The legacy parser suffixes every key; stripping one suffix restores the original names.
    expect(array_keys(data_get($this->application->parse(5), 'services')))->toBe(['web-pr-5', 'worker-pr-5-pr-5', 'db-pr-5'])
        ->and($this->application->composeServiceNamesForPreview(5))->toBe(['web', 'worker-pr-5']);
});

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

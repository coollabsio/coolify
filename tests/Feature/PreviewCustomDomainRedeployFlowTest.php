<?php

use App\Livewire\Project\Application\Previews;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    ServerSetting::create([
        'server_id' => $this->server->id,
        'wildcard_domain' => 'http://127.0.0.1.sslip.io',
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);
});

it('preserves a custom preview domain through save and simulated redeploy', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'fqdn' => 'https://example.com',
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'build_pack' => 'nixpacks',
    ]);

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
    ]);

    $customDomain = 'https://custom-preview.127.0.0.1.sslip.io';

    $application->refresh();
    $previewKey = $application->previews->search(fn ($p) => $p->id === $preview->id);

    $component = Livewire::test(Previews::class, ['application' => $application]);

    $component
        ->set('previewFqdns.'.$previewKey, $customDomain)
        ->call('save_preview', $preview->id)
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $preview->refresh();
    expect($preview->fqdn)->toBe($customDomain);

    // Simulate what ApplicationDeploymentJob does during redeploy
    $preview->generate_preview_fqdn(force: false);
    $preview->refresh();

    expect($preview->fqdn)->toBe($customDomain);
});

it('preserves custom docker compose preview domains through save and simulated redeploy', function () {
    $dockerCompose = <<<'YAML'
services:
  web:
    image: myapp/web:latest
    environment:
      - SERVICE_FQDN_WEB=${WEB_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => 'https://example.com'],
        ]),
    ]);

    $customDomain = 'https://custom-preview.127.0.0.1.sslip.io';

    $preview = ApplicationPreview::create([
        'application_id' => $application->id,
        'pull_request_id' => 42,
        'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        'docker_compose_domains' => json_encode([
            'web' => ['domain' => $customDomain],
        ]),
    ]);

    $application->refresh();
    $previewKey = $application->previews->search(fn ($p) => $p->id === $preview->id);

    $component = Livewire::test(Previews::class, ['application' => $application]);

    // The Previews component stores the primary fqdn only; compose-specific
    // domains are edited via the PreviewsCompose component.
    $component
        ->set('previewFqdns.'.$previewKey, $customDomain)
        ->call('save_preview', $preview->id)
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $preview->refresh();
    expect($preview->fqdn)->toBe($customDomain);

    // Simulate what ApplicationDeploymentJob does during redeploy
    $preview->generate_preview_fqdn_compose(force: false);
    $preview->refresh();

    expect($preview->docker_compose_domains)->toContain('custom-preview.127.0.0.1.sslip.io');
    expect($preview->docker_compose_domains)->not->toContain('42.127.0.0.1.sslip.io');
});

<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create(['team_id' => $team->id, 'private_key_id' => $privateKey->id]);
    $this->destination = StandaloneDocker::factory()->create(['server_id' => $server->id, 'network' => 'coolify-test']);
});

function makeApplicationWithEnv(): Application
{
    $application = Application::factory()->create([
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'nixpacks',
        'base_directory' => '/',
        'redirect' => 'no',
        'git_repository' => 'coollabsio/coolify',
        'git_branch' => 'main',
        'ports_exposes' => '3000',
    ]);

    EnvironmentVariable::create([
        'key' => 'SECRET_TOKEN',
        'value' => 'super-secret',
        'is_preview' => false,
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    return $application->refresh();
}

/**
 * The hash recipe as it was before environment variable values and noindex domains
 * were folded into it. Spelled out here on purpose so the test does not lean on the
 * implementation it is guarding.
 */
function preValuesConfigurationHash(Application $application): string
{
    $hash = base64_encode($application->fqdn.$application->git_repository.$application->git_branch.$application->git_commit_sha.$application->build_pack.$application->static_image.$application->install_command.$application->build_command.$application->start_command.$application->ports_exposes.$application->ports_mappings.$application->custom_network_aliases.$application->base_directory.$application->publish_directory.$application->dockerfile.$application->dockerfile_location.$application->custom_labels.$application->custom_docker_run_options.$application->dockerfile_target_build.$application->redirect.$application->custom_nginx_configuration.$application->settings?->use_build_secrets.$application->settings?->inject_build_args_to_dockerfile.$application->settings?->include_source_commit_in_build);
    $hash .= json_encode($application->environment_variables()->get(['value', 'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->sort());

    return md5($hash);
}

it('does not report pending changes for a hash stored before the recipe changed', function () {
    $application = makeApplicationWithEnv();

    $application->forceFill(['config_hash' => preValuesConfigurationHash($application)])->save();

    expect($application->hasPendingDeploymentConfigurationChanges())->toBeFalse();
});

it('still reports pending changes when the configuration really changed', function () {
    $application = makeApplicationWithEnv();

    $application->forceFill(['config_hash' => preValuesConfigurationHash($application)])->save();
    $application->forceFill(['ports_exposes' => '4000'])->save();

    expect($application->refresh()->hasPendingDeploymentConfigurationChanges())->toBeTrue();
});

it('still reports pending changes when no hash was ever stored', function () {
    $application = makeApplicationWithEnv();

    $application->forceFill(['config_hash' => null])->save();

    expect($application->refresh()->hasPendingDeploymentConfigurationChanges())->toBeTrue();
});

<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationSetting;

/**
 * @param  array<string, list<string>>  $scopes
 * @param  array<string, string>  $buildVariables
 */
function scopeComposeServiceEnvironment(array $composeFile, array $scopes, array $buildVariables, int $pullRequestId = 0): array
{
    $application = new Application(['build_pack' => 'dockercompose']);
    $application->setRelation('settings', new ApplicationSetting(['use_build_secrets' => false]));

    $job = (new ReflectionClass(ApplicationDeploymentJob::class))->newInstanceWithoutConstructor();
    $properties = [
        'application' => $application,
        'build_pack' => 'dockercompose',
        'pull_request_id' => $pullRequestId,
        'build_args' => collect(),
        'env_args' => collect($buildVariables),
        'compose_environment_scopes' => $scopes,
    ];
    foreach ($properties as $name => $value) {
        (new ReflectionProperty(ApplicationDeploymentJob::class, $name))->setValue($job, $value);
    }

    return (new ReflectionMethod(ApplicationDeploymentJob::class, 'scope_compose_service_environment'))->invoke($job, $composeFile);
}

it('gives each Compose service build only the scoped build arguments of that service', function () {
    $result = scopeComposeServiceEnvironment([
        'services' => [
            'api' => ['build' => './api'],
            'worker' => ['build' => ['context' => './worker', 'args' => ['EXISTING=1']]],
            'db' => ['image' => 'postgres'],
        ],
    ], ['API_TOKEN' => ['api'], 'DB_PASSWORD' => ['db']], ['SHARED' => 'x', 'API_TOKEN' => 'secret', 'DB_PASSWORD' => 'pw']);

    expect($result['services']['api'])->toBe(['build' => ['context' => './api', 'args' => ['API_TOKEN']]])
        ->and($result['services']['worker'])->toBe(['build' => ['context' => './worker', 'args' => ['EXISTING=1']]])
        ->and($result['services']['db'])->toBe(['image' => 'postgres']);
});

it('matches the scopes of a preview deployment to the service names of the Compose file', function () {
    $result = scopeComposeServiceEnvironment(
        ['services' => ['api-pr-7' => ['build' => './api']]],
        ['API_TOKEN' => ['api']],
        ['API_TOKEN' => 'secret'],
        pullRequestId: 7,
    );

    expect($result['services']['api-pr-7'])->toBe(['build' => ['context' => './api', 'args' => ['API_TOKEN']]]);
});

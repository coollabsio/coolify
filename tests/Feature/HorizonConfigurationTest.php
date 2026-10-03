<?php

use App\Jobs\ProcessGithubPullRequestWebhook;
use App\Jobs\StripeProcessJob;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/**
 * Evaluate config/horizon.php with the given environment variables, then restore them.
 *
 * @param  array<string, string|null>  $variables
 */
function loadHorizonConfigWithEnv(array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        $value === null ? putenv($name) : putenv("{$name}={$value}");
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    try {
        return require config_path('horizon.php');
    } finally {
        foreach ($previous as $name => $value) {
            $value === false ? putenv($name) : putenv("{$name}={$value}");
            if ($value === false) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}

/**
 * Parse every environment through Horizon's ProvisioningPlan, as `php artisan horizon` does on startup.
 *
 * @return array<string, array<string, SupervisorOptions>>
 */
function horizonSupervisorsFor(array $config): array
{
    $parsed = array_map(
        fn ($supervisors) => collect($supervisors)->all(),
        (new ProvisioningPlan('master', $config['environments'], $config['defaults']))->toSupervisorOptions(),
    );

    foreach ($parsed as $supervisors) {
        foreach ($supervisors as $options) {
            expect($options->connection)->toBe('redis')
                ->and($options->timeout)->toBe($config['worker_timeout'])
                ->and($options->toSupervisorCommand())->toContain('horizon:supervisor');
        }
    }

    return $parsed;
}

test('self-hosted keeps a single auto-scaling s6 supervisor in every environment', function () {
    $parsed = horizonSupervisorsFor(loadHorizonConfigWithEnv([
        'SELF_HOSTED' => 'true',
        'HORIZON_QUEUES' => null,
        'HORIZON_MIN_PROCESSES' => null,
        'HORIZON_MAX_PROCESSES' => null,
    ]));

    expect(array_keys($parsed))->toBe(['production', 'local']);

    foreach ($parsed as $supervisors) {
        expect(array_keys($supervisors))->toBe(['s6']);

        $s6 = $supervisors['s6'];
        expect($s6->queue)->toBe('high,default')
            ->and($s6->minProcesses)->toBe(1)
            ->and($s6->maxProcesses)->toBe(4)
            ->and($s6->autoScalingStrategy)->toBe('size')
            ->and($s6->maxJobs)->toBe(400)
            ->and($s6->memory)->toBe(128)
            ->and($s6->maxTries)->toBe(1);
    }
});

test('cloud production runs one fixed-size pool per queue with default process counts', function () {
    $parsed = horizonSupervisorsFor(loadHorizonConfigWithEnv([
        'SELF_HOSTED' => 'false',
        'HORIZON_DEPLOYMENTS_PROCESSES' => null,
        'HORIZON_CRONS_PROCESSES' => null,
        'HORIZON_HIGH_PROCESSES' => null,
        'HORIZON_DEFAULT_PROCESSES' => null,
        'HORIZON_MAINTENANCE_PROCESSES' => null,
        'HORIZON_WEBHOOKS_PROCESSES' => null,
    ]));

    $production = $parsed['production'];
    expect(array_keys($production))->toBe(['deployments', 'crons', 'high', 'default', 'maintenance', 'webhooks']);

    foreach (['deployments' => 60, 'crons' => 60, 'high' => 60, 'default' => 40, 'maintenance' => 10, 'webhooks' => 10] as $queue => $processes) {
        $pool = $production[$queue];
        expect($pool->queue)->toBe($queue)
            ->and($pool->balancing())->toBeFalse()
            ->and($pool->minProcesses)->toBe($processes)
            ->and($pool->maxProcesses)->toBe($processes)
            ->and($pool->maxJobs)->toBe(400)
            ->and($pool->memory)->toBe(128)
            ->and($pool->maxTries)->toBe(1)
            ->and($pool->timeout)->toBe($parsed['local']['s6']->timeout);
    }

    expect(array_keys($parsed['local']))->toBe(['s6']);
});

test('cloud pool sizes come from env and invalid values fall back to defaults', function () {
    $production = horizonSupervisorsFor(loadHorizonConfigWithEnv([
        'SELF_HOSTED' => 'false',
        'HORIZON_DEPLOYMENTS_PROCESSES' => '25',
        'HORIZON_CRONS_PROCESSES' => '0',
        'HORIZON_HIGH_PROCESSES' => 'abc',
        'HORIZON_DEFAULT_PROCESSES' => '-5',
        'HORIZON_MAINTENANCE_PROCESSES' => '4',
        'HORIZON_WEBHOOKS_PROCESSES' => '6',
    ]))['production'];

    expect($production['deployments']->maxProcesses)->toBe(25)
        ->and($production['deployments']->minProcesses)->toBe(25)
        ->and($production['crons']->maxProcesses)->toBe(60)
        ->and($production['high']->maxProcesses)->toBe(60)
        ->and($production['default']->maxProcesses)->toBe(40)
        ->and($production['maintenance']->minProcesses)->toBe(4)
        ->and($production['maintenance']->maxProcesses)->toBe(4)
        ->and($production['webhooks']->minProcesses)->toBe(6)
        ->and($production['webhooks']->maxProcesses)->toBe(6);

    $fallback = horizonSupervisorsFor(loadHorizonConfigWithEnv([
        'SELF_HOSTED' => 'false',
        'HORIZON_MAINTENANCE_PROCESSES' => 'ten',
    ]))['production'];

    expect($fallback['maintenance']->maxProcesses)->toBe(10);
});

test('cloud queue helpers route to queues that have a dedicated pool', function () {
    config(['constants.coolify.self_hosted' => false]);

    $production = horizonSupervisorsFor(loadHorizonConfigWithEnv(['SELF_HOSTED' => 'false']))['production'];

    expect($production)->toHaveKeys([deployment_queue(), crons_queue(), maintenance_queue(), webhooks_queue()]);
});

test('self-hosted queue helpers route to a queue the s6 supervisor drains', function () {
    config(['constants.coolify.self_hosted' => true]);

    $parsed = horizonSupervisorsFor(loadHorizonConfigWithEnv([
        'SELF_HOSTED' => 'true',
        'HORIZON_QUEUES' => null,
        'HORIZON_MAINTENANCE_PROCESSES' => '3',
    ]));

    foreach ($parsed as $supervisors) {
        expect(array_keys($supervisors))->toBe(['s6'])
            ->and(explode(',', $supervisors['s6']->queue))
            ->toContain(deployment_queue(), crons_queue(), maintenance_queue(), webhooks_queue());
    }
});

test('webhook jobs run on the webhooks queue on cloud and on high on self-hosted', function (bool $selfHosted, string $queue) {
    config(['constants.coolify.self_hosted' => $selfHosted]);

    $stripeJob = new StripeProcessJob(['type' => 'invoice.paid']);
    $githubJob = new ProcessGithubPullRequestWebhook(
        applicationId: 1,
        githubAppId: null,
        action: 'opened',
        pullRequestId: 1,
        pullRequestHtmlUrl: 'https://github.com/coollabsio/coolify/pull/1',
        pullRequestTitle: null,
        beforeSha: null,
        afterSha: null,
        commitSha: 'abc123',
        authorAssociation: null,
        fullName: 'coollabsio/coolify',
    );

    expect($stripeJob->queue)->toBe($queue)
        ->and($githubJob->queue)->toBe($queue);
})->with([
    'cloud' => [false, 'webhooks'],
    'self-hosted' => [true, 'high'],
]);

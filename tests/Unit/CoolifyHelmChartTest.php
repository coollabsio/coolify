<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

function coolifyHelmChartPath(string $path = ''): string
{
    return dirname(__DIR__, 2).'/'.trim('charts/coolify/'.$path, '/');
}

function coolifyCopyHelmChart(string $source, string $destination): void
{
    mkdir($destination, 0755, true);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathName();

        if ($item->isDir()) {
            mkdir($target, 0755, true);

            continue;
        }

        if (str_ends_with($item->getFilename(), '.tgz')) {
            continue;
        }

        copy($item->getPathname(), $target);
    }
}

function coolifyRunHelm(array $arguments, string $workingDirectory): Process
{
    $helm = trim((string) shell_exec('command -v helm'));

    if ($helm === '') {
        test()->markTestSkipped('Helm is not installed.');
    }

    $process = new Process(array_merge([$helm], $arguments), $workingDirectory);
    $process->setTimeout(180);
    $process->run();

    return $process;
}

function coolifyRemoveDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

it('defines a first-party Coolify chart with runtime dependencies', function () {
    $chart = Yaml::parseFile(coolifyHelmChartPath('Chart.yaml'));
    $values = Yaml::parseFile(coolifyHelmChartPath('values.yaml'));
    $dependencies = collect($chart['dependencies'])->pluck('name')->all();

    expect($chart['name'])->toBe('coolify')
        ->and($chart['type'])->toBe('application')
        ->and($chart['appVersion'])->toBe('4.1.0')
        ->and($dependencies)->toContain('postgresql', 'redis')
        ->and(data_get($values, 'env.secretName'))->toBe('coolify-env')
        ->and(data_get($values, 'postgresql.auth.existingSecret'))->toBe('coolify-env')
        ->and(data_get($values, 'redis.auth.existingSecret'))->toBe('coolify-env');
});

it('renders Kubernetes-native Coolify workloads with Helm', function () {
    $temporaryChart = sys_get_temp_dir().'/coolify-chart-'.uniqid();

    try {
        coolifyCopyHelmChart(coolifyHelmChartPath(), $temporaryChart);

        $dependencyBuild = coolifyRunHelm(['dependency', 'build', $temporaryChart], dirname(__DIR__, 2));

        expect($dependencyBuild->isSuccessful())->toBeTrue($dependencyBuild->getErrorOutput());

        $render = coolifyRunHelm([
            'template',
            'coolify',
            $temporaryChart,
            '--namespace',
            'coolify-system',
            '--set',
            'ingress.enabled=true',
            '--set',
            'autoscaling.web.enabled=true',
            '--set',
            'podDisruptionBudget.web.enabled=true',
            '--set',
            'networkPolicy.enabled=true',
            '--set-string',
            'env.extra.ROOT_USERNAME=Root User',
        ], dirname(__DIR__, 2));

        $output = $render->getOutput();
        $documents = collect(explode("\n---", $output))
            ->map(fn (string $document) => Yaml::parse($document))
            ->filter();
        $realtimeDeployment = $documents->first(fn (?array $document) => data_get($document, 'kind') === 'Deployment'
            && data_get($document, 'metadata.name') === 'coolify-realtime');

        expect($render->isSuccessful())->toBeTrue($render->getErrorOutput())
            ->and($output)->toContain('kind: Deployment')
            ->and($output)->toContain('name: coolify-web')
            ->and($output)->toContain('name: coolify-worker')
            ->and($output)->toContain('kind: CronJob')
            ->and($output)->toContain('name: coolify-scheduler')
            ->and($output)->toContain('kind: Job')
            ->and($output)->toContain('name: coolify-migrate')
            ->and($output)->toContain('kind: HorizontalPodAutoscaler')
            ->and($output)->toContain('kind: PodDisruptionBudget')
            ->and($output)->toContain('kind: NetworkPolicy')
            ->and($output)->toContain('mountPath: /var/www/html/.env')
            ->and($output)->toContain('ROOT_USERNAME="Root User"')
            ->and(data_get($realtimeDeployment, 'spec.template.spec.volumes'))->toBeNull()
            ->and($output)->not->toContain('/var/run/docker.sock');
    } finally {
        coolifyRemoveDirectory($temporaryChart);
    }
});

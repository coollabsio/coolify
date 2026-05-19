<?php

use App\Models\Service;
use App\Models\ServiceApplication;
use App\Services\Kubernetes\KubernetesServiceManifestGenerator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

function kubernetesService(array $attributes = []): Service
{
    $service = new Service(array_merge([
        'name' => 'analytics',
        'uuid' => 'svc123456789',
    ], $attributes));
    $service->setRelation('applications', new EloquentCollection([
        new ServiceApplication([
            'name' => 'web',
            'fqdn' => 'https://analytics.example.com',
            'image' => 'ghcr.io/example/web:1',
        ]),
    ]));
    $service->setRelation('databases', new EloquentCollection);
    $service->setRelation('environment_variables', new EloquentCollection);

    return $service;
}

it('generates kubernetes resources for image based services', function () {
    $compose = [
        'services' => [
            'web' => [
                'image' => 'ghcr.io/example/web:1',
                'ports' => ['8080:80'],
                'environment' => ['APP_ENV' => 'production'],
                'volumes' => ['web-data:/data'],
            ],
        ],
        'volumes' => ['web-data' => []],
    ];

    $resources = (new KubernetesServiceManifestGenerator)->generate(kubernetesService(), $compose, [
        'namespace' => 'production',
        'create_namespace' => true,
        'ingress_class' => 'nginx',
        'ingress_tls_secret' => 'analytics-tls',
        'service_account_name' => 'coolify-workload',
        'create_service_account' => true,
        'image_pull_secrets' => 'regcred',
        'storage_size' => '5Gi',
        'autoscaling' => true,
        'pod_disruption_budget_enabled' => true,
    ]);

    expect(collect($resources)->pluck('kind')->all())
        ->toContain('Namespace', 'ServiceAccount', 'PersistentVolumeClaim', 'Secret', 'Deployment', 'Service', 'Ingress', 'HorizontalPodAutoscaler', 'PodDisruptionBudget');

    $deployment = collect($resources)->firstWhere('kind', 'Deployment');
    $ingress = collect($resources)->firstWhere('kind', 'Ingress');

    expect(data_get($deployment, 'metadata.labels')['coolify.io/service-uuid'])->toBe('svc123456789')
        ->and(data_get($deployment, 'spec.template.spec.serviceAccountName'))->toBe('coolify-workload')
        ->and(data_get($deployment, 'spec.template.spec.imagePullSecrets.0.name'))->toBe('regcred')
        ->and(data_get($ingress, 'spec.rules.0.host'))->toBe('analytics.example.com')
        ->and(data_get($ingress, 'spec.tls.0.secretName'))->toBe('analytics-tls');
});

it('rejects service compose build and bind mount definitions', function () {
    $generator = new KubernetesServiceManifestGenerator;

    expect(fn () => $generator->generate(kubernetesService(), [
        'services' => ['web' => ['build' => '.', 'image' => 'example/web']],
    ]))->toThrow(InvalidArgumentException::class, 'uses build');

    expect(fn () => $generator->generate(kubernetesService(), [
        'services' => ['web' => ['image' => 'example/web', 'volumes' => ['./data:/data']]],
    ]))->toThrow(InvalidArgumentException::class, 'uses bind mount');
});

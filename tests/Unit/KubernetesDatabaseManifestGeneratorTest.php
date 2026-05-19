<?php

use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Services\Kubernetes\KubernetesDatabaseManifestGenerator;
use Illuminate\Encryption\Encrypter;

it('generates kubernetes resources for standalone databases', function () {
    StandalonePostgresql::encryptUsing(new Encrypter(str_repeat('a', 32), 'aes-256-cbc'));

    $database = new StandalonePostgresql([
        'name' => 'main-db',
        'uuid' => 'db123456789',
        'image' => 'postgres:16-alpine',
        'postgres_user' => 'coolify',
        'postgres_password' => 'secret',
        'postgres_db' => 'app',
    ]);

    $resources = (new KubernetesDatabaseManifestGenerator)->generate($database, [
        'namespace' => 'production',
        'create_namespace' => true,
        'service_type' => 'ClusterIP',
        'storage_size' => '10Gi',
        'storage_class' => 'fast',
    ]);

    expect(collect($resources)->pluck('kind')->all())->toBe(['Namespace', 'Secret', 'Service', 'StatefulSet']);

    $statefulSet = collect($resources)->firstWhere('kind', 'StatefulSet');
    $secret = collect($resources)->firstWhere('kind', 'Secret');

    expect(data_get($statefulSet, 'metadata.labels')['coolify.io/database-uuid'])->toBe('db123456789')
        ->and(data_get($statefulSet, 'spec.template.spec.containers.0.image'))->toBe('postgres:16-alpine')
        ->and(data_get($statefulSet, 'spec.volumeClaimTemplates.0.spec.resources.requests.storage'))->toBe('10Gi')
        ->and(data_get($statefulSet, 'spec.volumeClaimTemplates.0.spec.storageClassName'))->toBe('fast')
        ->and(data_get($statefulSet, 'spec.volumeClaimTemplates.0.metadata.labels')['coolify.io/database-uuid'])->toBe('db123456789')
        ->and(base64_decode(data_get($secret, 'data.POSTGRES_PASSWORD')))->toBe('secret');
});

it('generates redis without a secret when no runtime environment is needed', function () {
    $database = new StandaloneRedis([
        'name' => 'cache',
        'uuid' => 'redis123456',
        'image' => 'redis:7-alpine',
    ]);

    $resources = (new KubernetesDatabaseManifestGenerator)->generate($database, ['namespace' => 'default']);

    expect(collect($resources)->pluck('kind')->all())->toBe(['Service', 'StatefulSet'])
        ->and(data_get(collect($resources)->firstWhere('kind', 'StatefulSet'), 'spec.template.spec.containers.0.ports.0.containerPort'))->toBe(6379);
});

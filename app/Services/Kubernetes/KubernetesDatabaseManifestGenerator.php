<?php

namespace App\Services\Kubernetes;

use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Symfony\Component\Yaml\Yaml;

class KubernetesDatabaseManifestGenerator
{
    private KubernetesManifestData $data;

    public function __construct()
    {
        $this->data = new KubernetesManifestData;
    }

    public function generate(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, array $options = []): array
    {
        $name = $this->resourceName($database);
        $namespace = $options['namespace'] ?? 'default';
        $labels = $this->labels($database, $name);
        $secret = $this->secret($database, $name, $namespace, $labels);
        $resources = [];

        if (($options['create_namespace'] ?? false) === true) {
            $resources[] = ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'coolify']]];
        }

        if ($secret !== null) {
            $resources[] = $secret;
        }

        $resources[] = $this->service($name, $namespace, $labels, $this->port($database), $options);
        $resources[] = $this->statefulSet($database, $name, $namespace, $labels, $secret !== null, $options);

        return $resources;
    }

    public function toYaml(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, array $options = []): string
    {
        return collect($this->generate($database, $options))
            ->map(fn (array $resource) => Yaml::dump($resource, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK))
            ->implode("---\n");
    }

    public function resourceName(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): string
    {
        $base = str($database->name ?: $database->uuid)->lower()->replaceMatches('/[^a-z0-9-]+/', '-')->replaceMatches('/-+/', '-')->trim('-')->toString();

        return $this->data->dnsLabel($base.'-'.substr((string) $database->uuid, 0, 8), 'database');
    }

    private function statefulSet(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, string $name, string $namespace, array $labels, bool $hasSecret, array $options): array
    {
        $container = ['name' => 'database', 'image' => $this->image($database), 'ports' => [['name' => 'database', 'containerPort' => $this->port($database), 'protocol' => 'TCP']]];

        if ($hasSecret) {
            $container['envFrom'] = [['secretRef' => ['name' => "{$name}-env"]]];
        }

        $container['volumeMounts'] = [['name' => 'data', 'mountPath' => $this->mountPath($database)]];
        $podSpec = ['containers' => [$container]];
        $imagePullSecrets = $this->data->stringList($options['image_pull_secrets'] ?? null);

        if ($imagePullSecrets !== []) {
            $podSpec['imagePullSecrets'] = collect($imagePullSecrets)->map(fn (string $secret) => ['name' => $secret])->values()->toArray();
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'StatefulSet',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => [
                'serviceName' => $name,
                'replicas' => 1,
                'selector' => ['matchLabels' => $this->selector($name)],
                'template' => ['metadata' => ['labels' => $labels], 'spec' => $podSpec],
                'volumeClaimTemplates' => [$this->volumeClaimTemplate($options, $labels)],
            ],
        ];
    }

    private function service(string $name, string $namespace, array $labels, int $port, array $options): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => $name, 'namespace' => $namespace, 'labels' => $labels],
            'spec' => ['type' => $options['service_type'] ?? 'ClusterIP', 'selector' => $this->selector($name), 'ports' => [['name' => 'database', 'port' => $port, 'targetPort' => $port]]],
        ];
    }

    private function secret(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, string $name, string $namespace, array $labels): ?array
    {
        $data = collect($this->environment($database))->filter(fn ($value) => filled($value))->map(fn ($value) => base64_encode((string) $value));

        return $data->isEmpty() ? null : ['apiVersion' => 'v1', 'kind' => 'Secret', 'metadata' => ['name' => "{$name}-env", 'namespace' => $namespace, 'labels' => $labels], 'type' => 'Opaque', 'data' => $data->toArray()];
    }

    private function environment(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): array
    {
        return match ($database->getMorphClass()) {
            StandalonePostgresql::class => ['POSTGRES_USER' => $database->postgres_user, 'POSTGRES_PASSWORD' => $database->postgres_password, 'POSTGRES_DB' => $database->postgres_db],
            StandaloneMysql::class => ['MYSQL_ROOT_PASSWORD' => $database->mysql_root_password, 'MYSQL_USER' => $database->mysql_user, 'MYSQL_PASSWORD' => $database->mysql_password, 'MYSQL_DATABASE' => $database->mysql_database],
            StandaloneMariadb::class => ['MARIADB_ROOT_PASSWORD' => $database->mariadb_root_password, 'MARIADB_USER' => $database->mariadb_user, 'MARIADB_PASSWORD' => $database->mariadb_password, 'MARIADB_DATABASE' => $database->mariadb_database],
            StandaloneMongodb::class => ['MONGO_INITDB_ROOT_USERNAME' => $database->mongo_initdb_root_username, 'MONGO_INITDB_ROOT_PASSWORD' => $database->mongo_initdb_root_password, 'MONGO_INITDB_DATABASE' => $database->mongo_initdb_database],
            StandaloneClickhouse::class => ['CLICKHOUSE_USER' => $database->clickhouse_admin_user, 'CLICKHOUSE_PASSWORD' => $database->clickhouse_admin_password, 'CLICKHOUSE_DB' => $database->clickhouse_db],
            StandaloneRedis::class => [],
            StandaloneKeydb::class => ['KEYDB_PASSWORD' => $database->keydb_password],
            StandaloneDragonfly::class => ['DRAGONFLY_PASSWORD' => $database->dragonfly_password],
            default => [],
        };
    }

    private function image(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database): string
    {
        return (string) ($database->getAttributes()['image'] ?? $database->image);
    }

    private function volumeClaimTemplate(array $options, array $labels): array
    {
        $spec = ['accessModes' => ['ReadWriteOnce'], 'resources' => ['requests' => ['storage' => $options['storage_size'] ?? '1Gi']]];

        if (filled($options['storage_class'] ?? null)) {
            $spec['storageClassName'] = $options['storage_class'];
        }

        return ['metadata' => ['name' => 'data', 'labels' => $labels], 'spec' => $spec];
    }

    private function labels(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse $database, string $name): array
    {
        return ['app.kubernetes.io/name' => $name, 'app.kubernetes.io/managed-by' => 'coolify', 'app.kubernetes.io/component' => 'database', 'coolify.io/database-uuid' => (string) $database->uuid];
    }

    private function selector(string $name): array
    {
        return ['app.kubernetes.io/name' => $name, 'app.kubernetes.io/managed-by' => 'coolify'];
    }

    private function port($database): int
    {
        return match ($database->getMorphClass()) {
            StandalonePostgresql::class => 5432,
            StandaloneMysql::class, StandaloneMariadb::class => 3306,
            StandaloneMongodb::class => 27017,
            StandaloneClickhouse::class => 8123,
            default => 6379,
        };
    }

    private function mountPath($database): string
    {
        return match ($database->getMorphClass()) {
            StandalonePostgresql::class => '/var/lib/postgresql/data',
            StandaloneMysql::class, StandaloneMariadb::class => '/var/lib/mysql',
            StandaloneMongodb::class => '/data/db',
            StandaloneClickhouse::class => '/var/lib/clickhouse',
            default => '/data',
        };
    }
}

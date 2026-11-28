<?php

use App\Actions\Node\ApplyNodeClusterNetworkRevision;
use App\Actions\Node\BackupNodeVolume;
use App\Actions\Node\BuildNodeClusterIngressRoutes;
use App\Actions\Node\CreateClusterDockerImageWorkload;
use App\Actions\Node\CreateClusterStack;
use App\Actions\Node\CreateDeploymentOperation;
use App\Actions\Node\CreateMoveOperation;
use App\Actions\Node\DeleteNodeVolumeData;
use App\Actions\Node\DispatchWorkloadDeployment;
use App\Actions\Node\FetchContainers as FetchContainersAction;
use App\Actions\Node\PrepareNodeWorkloadRevision;
use App\Actions\Node\QueueNodeClusterNetworkRevision;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ReconcileNodeWorkloads;
use App\Actions\Node\RemoveNodeVolume;
use App\Actions\Node\RestoreNodeVolume;
use App\Enums\NodeOperationStatus;
use App\Jobs\DeleteNodeStackJob;
use App\Jobs\DeleteNodeWorkloadJob;
use App\Jobs\DeployNodeStackJob;
use App\Jobs\MoveNodeWorkloadJob;
use App\Livewire\Project\ClusterApplication\Show as ClusterApplicationShow;
use App\Livewire\Project\ClusterStack\Show as ClusterStackShow;
use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeStack;
use App\Models\NodeVolume;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use App\Services\NodeStack\NodeStackCompose;
use App\Services\NodeStack\NodeStackComposeMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return list<string> */
function stackNodeCapabilities(array $extra = []): array
{
    return [
        'network.wireguard.key.ensure.v1',
        'network.wireguard.reconcile.v1',
        'network.wireguard.inspect.v1',
        'network.firewall.reconcile.v1',
        'network.firewall.inspect.v1',
        'discovery.corrosion.inspect.v1',
        'discovery.corrosion.reconcile.v1',
        'ingress.reconcile.v1',
        'workload.deploy.v1',
        'workload.lifecycle.v1',
        'workload.healthcheck.v1',
        'volume.mounts.v1',
        'container.list.v1',
        'workload.dns_search.v1',
        'workload.files.v1',
        ...$extra,
    ];
}

function stackNode(Team $team, NodeCluster $cluster, string $name, string $ip, array $capabilities): Node
{
    $node = Node::factory()->create([
        'team_id' => $team->id,
        'node_cluster_id' => $cluster->id,
        'private_key_id' => (PrivateKey::query()->where('team_id', $team->id)->first() ?? PrivateKey::factory()->create(['team_id' => $team->id]))->id,
        'name' => $name,
        'ip' => $ip,
        'is_usable' => true,
        'is_reachable' => true,
        'is_ingress' => true,
        'workload_cidr' => '10.250.'.random_int(1, 200).'.0/24',
        'wireguard_ip' => '10.240.0.'.random_int(2, 250),
        'wireguard_public_key' => 'key-'.Str::random(8),
        'network_status' => 'converged',
        'corrosion_status' => 'converged',
        'metadata' => [
            'cpu_usage_percent' => 10,
            'memory_bytes' => 1_000,
            'memory_used_bytes' => 100,
            'disk_total_bytes' => 1_000,
            'disk_available_bytes' => 900,
            'collected_at' => now()->toIso8601String(),
        ],
    ]);
    Cache::put($node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => $capabilities]);

    return $node;
}

function stackCompose(): string
{
    return <<<'YAML'
services:
  app:
    image: ghcr.io/acme/app:1
    entrypoint: ["/bin/sh", "-c"]
    command: "./start.sh --db postgres"
    environment:
      - SERVICE_URL_APP_3000
      - APP_URL=${SERVICE_URL_APP}
      - DB_USER=$SERVICE_USER_POSTGRES
      - DB_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - DB_NAME=${POSTGRES_DB:-appdb}
      - SHELL_VALUE=$${HOME}
    volumes:
      - ./uploads:/app/uploads
      - type: bind
        source: ./config.yml
        target: /app/config.yml
        content: |
          name: ${SERVICE_NAME_APP}
          port: 3000
    depends_on:
      postgres:
        condition: service_healthy
      migrate:
        condition: service_completed_successfully
  migrate:
    image: ghcr.io/acme/app:1
    command: ["./migrate.sh"]
    depends_on:
      postgres:
        condition: service_healthy
  postgres:
    image: postgres:16-alpine
    environment:
      - POSTGRES_USER=${SERVICE_USER_POSTGRES}
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}
      - POSTGRES_DB=${POSTGRES_DB:-appdb}
    volumes:
      - postgres-data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 20s
      retries: 10
YAML;
}

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'instance_uuid' => 'instance-test']);
    config()->set('app.env', 'production');
    setNodesEnabled(true);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->team->update(['show_boarding' => false]);
    Cache::flush();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->cluster = NodeCluster::factory()->create(['team_id' => $this->team->id, 'created_by_user_id' => $this->user->id, 'network_status' => 'active']);
    $this->node = stackNode($this->team, $this->cluster, 'node-a', '203.0.113.10', stackNodeCapabilities());
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createStack(object $test, ?string $compose = null, string $name = 'shop'): NodeStack
{
    Queue::fake();

    return CreateClusterStack::run($test->project, $test->environment, $test->cluster, $compose ?? stackCompose(), $test->user, name: $name);
}

describe('availability', function () {
    it('tells which templates can run on Nodes', function () {
        $reasons = fn (bool $underscoreNames) => collect(glob(base_path('templates/compose/*.{yaml,yml}'), GLOB_BRACE))
            ->mapWithKeys(fn (string $path): array => [pathinfo($path, PATHINFO_FILENAME) => NodeStackCompose::unavailableReason(NodeStackCompose::parse(file_get_contents($path)), $underscoreNames)]);
        $withUnderscores = $reasons(true);
        $withoutUnderscores = $reasons(false);

        // Decision 0023 counts 316 runnable templates; step 1 ran 296. Decision 0024 shares a volume between
        // the services of a stack (also with volumes_from), which adds 20: 22 templates had that as their
        // first reason, and appwrite and trigger also mount the Docker socket. 9 runnable templates have a
        // service name with `_`, which needs discovery.names.underscore.v1 on every Node.
        expect($withUnderscores->filter(fn (?string $reason): bool => $reason === null)->count())->toBe(316)
            ->and($withoutUnderscores->filter(fn (?string $reason): bool => $reason === null)->count())->toBe(307)
            ->and($withUnderscores['twenty'])->toBeNull()
            ->and($withUnderscores['espocrm'])->toBeNull()
            ->and($withUnderscores['appwrite'])->toBe('The service openruntimes-executor mounts the Docker socket.')
            ->and($withUnderscores['trigger'])->toBe('The service docker-proxy mounts the Docker socket.')
            ->and($withUnderscores['portainer'])->toBe('The service portainer mounts the Docker socket.')
            ->and($withUnderscores['gitea'])->toContain('publishes ports')
            ->and($withUnderscores['n8n-with-postgresql'])->toBeNull()
            ->and($withUnderscores['rallly'])->toBeNull()
            ->and($withoutUnderscores['rallly'])->toBe('The service rallly_db has a name with _, which needs Sentinel with discovery.names.underscore.v1 on every server of the cluster.');
    });

    it('rejects each feature that a workload does not have', function (string $service, string $reason) {
        $yaml = NodeStackCompose::parse("services:\n  web:\n    image: nginx\n{$service}");

        expect(NodeStackCompose::unavailableReason($yaml))->toBe("The service web {$reason}.");
    })->with([
        'socket' => ["    volumes:\n      - /var/run/docker.sock:/var/run/docker.sock\n", 'mounts the Docker socket'],
        'host path' => ["    volumes:\n      - /srv/data:/data\n", 'mounts the host path /srv/data'],
        'privileged' => ["    privileged: true\n", 'runs privileged'],
        'cap_add' => ["    cap_add: [NET_ADMIN]\n", 'adds Linux capabilities (cap_add)'],
        'devices' => ["    devices: ['/dev/fuse']\n", 'uses host devices'],
        'security_opt' => ["    security_opt: ['seccomp:unconfined']\n", 'changes security options (security_opt)'],
        'network_mode' => ["    network_mode: host\n", 'sets network_mode'],
        'pid' => ["    pid: host\n", 'shares a PID namespace (pid)'],
        'sysctls' => ["    sysctls: {net.core.somaxconn: 1024}\n", 'sets kernel parameters (sysctls)'],
        'ports' => ["    ports: ['8080:80']\n", 'publishes ports, which needs Sentinel with network.firewall.published_ports.v1 on every server of the cluster'],
    ]);

    it('shows Not available on Nodes in the template picker when a cluster is the destination', function () {
        $parameters = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid];
        Event::listen(RouteMatched::class, function (RouteMatched $event) use ($parameters): void {
            foreach ($parameters as $name => $value) {
                $event->route->setParameter($name, $value);
            }
        });

        Livewire::test(Select::class)
            ->call('loadServers')
            ->call('setType', 'one-click-service-portainer')
            ->assertSet('current_step', 'targets')
            ->assertSet('nodesUnavailableReason', 'The service portainer mounts the Docker socket.')
            ->assertSee('Not available on Nodes')
            ->assertDontSee('setCluster');
    });
});

describe('mapping', function () {
    it('maps a template with an app and PostgreSQL to workloads with shared generated secrets', function () {
        $stack = createStack($this, file_get_contents(base_path('templates/compose/n8n-with-postgresql.yaml')), 'n8n');
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $n8n = $services['n8n'];
        $postgres = $services['postgresql'];
        $n8nEnv = $n8n->environment_variables()->pluck('value', 'key');
        $postgresEnv = $postgres->environment_variables()->pluck('value', 'key');
        $variables = $stack->variables;

        expect($services->keys()->all())->toBe(['n8n', 'task-runners', 'postgresql'])
            ->and($n8nEnv['DB_POSTGRESDB_PASSWORD'])->toBe($postgresEnv['POSTGRES_PASSWORD'])
            ->and($n8nEnv['DB_POSTGRESDB_PASSWORD'])->toBe($variables['SERVICE_PASSWORD_POSTGRES'])
            ->and(strlen($variables['SERVICE_PASSWORD_POSTGRES']))->toBe(32)
            ->and($n8nEnv['DB_POSTGRESDB_USER'])->toBe($postgresEnv['POSTGRES_USER'])
            ->and($n8nEnv['DB_POSTGRESDB_HOST'])->toBe('postgresql')
            ->and($n8nEnv['GENERIC_TIMEZONE'])->toBe('UTC')
            ->and($n8nEnv['WEBHOOK_URL'])->toBe('http://n8n-'.strtolower($stack->uuid).'.203.0.113.10.sslip.io')
            ->and($n8n->domain_routes)->toBe([['scheme' => 'http', 'host' => 'n8n-'.strtolower($stack->uuid).'.203.0.113.10.sslip.io', 'port' => 5678, 'path' => '', 'noindex' => false]])
            ->and($n8n->stack_health_check['command'])->toBe('wget -qO- http://127.0.0.1:5678/healthz')
            ->and($n8n->volumes()->pluck('mount_path', 'name')->all())->toBe(['n8n-data' => '/home/node/.n8n'])
            ->and($postgres->stack_health_check)->toMatchArray(['command' => 'pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}', 'interval_seconds' => 5, 'timeout_seconds' => 20, 'retries' => 10])
            ->and($postgres->revisions()->sole()->image)->toBe('docker.io/library/postgres:16-alpine')
            ->and($stack->dependencies())->toBe(['n8n' => ['postgresql' => 'service_healthy'], 'task-runners' => ['n8n' => 'service_started'], 'postgresql' => []])
            ->and($stack->deployOrder())->toBe(['postgresql', 'n8n', 'task-runners']);
    });

    it('maps entrypoints, interpolation, content files, relative binds, and one-time services', function () {
        $stack = createStack($this);
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $app = $services['app'];
        $revision = $app->revisions()->sole();
        $environment = $app->environment_variables()->pluck('value', 'key');

        expect($revision->configuration['command'])->toBe(['/bin/sh', '-c', './start.sh', '--db', 'postgres'])
            ->and($revision->configuration['restart_policy'])->toBe('unless-stopped')
            ->and($revision->configuration['files'])->toBe([[
                'path' => '/app/config.yml',
                'content_base64' => base64_encode("name: \${SERVICE_NAME_APP}\nport: 3000\n"),
                'mode' => '0644',
                'directory' => false,
            ]])
            ->and($environment['DB_NAME'])->toBe('appdb')
            ->and($environment['SHELL_VALUE'])->toBe('${HOME}')
            ->and($environment['DB_PASSWORD'])->toBe($stack->variables['SERVICE_PASSWORD_POSTGRES'])
            ->and($app->volumes()->pluck('mount_path', 'name')->all())->toBe(['uploads' => '/app/uploads'])
            ->and($app->description)->toContain('entrypoint')
            ->and($services['migrate']->revisions()->sole()->configuration['restart_policy'])->toBe('no')
            ->and($app->internal_dns_name)->not->toBeNull()
            ->and($stack->dns_label)->toBe('shop');
    });

    it('resolves Compose interpolation', function (string $value, string $expected) {
        expect(NodeStackComposeMapper::interpolate($value, ['SET' => 'yes', 'EMPTY' => '']))->toBe($expected);
    })->with([
        ['${SET}', 'yes'],
        ['$SET-x', 'yes-x'],
        ['${MISSING:-fallback}', 'fallback'],
        ['${EMPTY:-fallback}', 'fallback'],
        ['${EMPTY-fallback}', ''],
        ['${MISSING:-${SET}}', 'yes'],
        ['${SET:+other}', 'other'],
        ['$${SET}', '${SET}'],
        ['100$', '100$'],
    ]);

    it('qualifies an image like the inventory reports it, with latest for an untagged image', function (string $image, string $expected) {
        expect(CreateClusterDockerImageWorkload::qualifiedImage($image))->toBe($expected);
    })->with([
        ['searxng/searxng', 'docker.io/searxng/searxng:latest'],
        ['nginx', 'docker.io/library/nginx:latest'],
        ['localhost:5000/app', 'localhost:5000/app:latest'],
        ['redis:7', 'docker.io/library/redis:7'],
        ['ghcr.io/acme/app@sha256:abc', 'ghcr.io/acme/app@sha256:abc'],
        ['ghcr.io/acme/app:1.2@sha256:abc', 'ghcr.io/acme/app:1.2@sha256:abc'],
    ]);

    it('gives each stack of a cluster its own DNS label and never a reserved one', function () {
        $first = createStack($this, "services:\n  web:\n    image: nginx\n", 'default');
        $second = createStack($this, "services:\n  web:\n    image: nginx\n", 'default');

        expect($first->dns_label)->toBe('default-2')
            ->and($second->dns_label)->toBe('default-3');
    });

    it('rejects a stack whose services depend on each other in a cycle', function () {
        expect(fn () => createStack($this, "services:\n  a:\n    image: nginx\n    depends_on: [b]\n  b:\n    image: nginx\n    depends_on: [a]\n"))
            ->toThrow(RuntimeException::class, 'depend on each other in a cycle');
        expect(NodeStack::query()->count())->toBe(0)
            ->and(NodeWorkload::query()->count())->toBe(0);
    });
});

describe('names and traffic', function () {
    it('adds a name for each stack service in the namespace of the stack', function () {
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n");
        $services = $stack->workloads()->get()->keyBy('stack_service');

        $names = collect(BuildNodeClusterIngressRoutes::names($this->cluster));

        expect($names->where('namespace', 'default')->pluck('workload_id')->sort()->values()->all())->toBe($services->pluck('uuid')->sort()->values()->all())
            ->and($names->where('namespace', 'shop')->values()->all())->toBe([
                ['name' => 'web', 'workload_id' => $services['web']->uuid, 'namespace' => 'shop'],
                ['name' => 'db', 'workload_id' => $services['db']->uuid, 'namespace' => 'shop'],
            ]);
    });

    it('rejects a service name with an underscore when a server would reject the whole name table', function () {
        expect(fn () => createStack($this, "services:\n  web:\n    image: nginx\n  rallly_db:\n    image: postgres\n"))
            ->toThrow(RuntimeException::class, 'The service rallly_db has a name with _');
    });

    it('sends a service name with an underscore only while every server of the cluster accepts it', function () {
        Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => stackNodeCapabilities([NodeCluster::UNDERSCORE_NAMES_CAPABILITY])]);
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n  rallly_db:\n    image: postgres\n");
        $database = $stack->workloads()->where('stack_service', 'rallly_db')->sole();

        expect(collect(BuildNodeClusterIngressRoutes::names($this->cluster))->where('namespace', 'shop')->pluck('name')->all())->toBe(['web', 'rallly_db'])
            ->and($database->internal_dns_name)->toBe('shop-rallly-db');

        stackNode($this->team, $this->cluster, 'old', '203.0.113.11', stackNodeCapabilities());

        expect(collect(BuildNodeClusterIngressRoutes::names($this->cluster->refresh()))->where('namespace', 'shop')->pluck('name')->all())->toBe(['web']);
    });

    it('allows all traffic between the services of a stack on Nodes that support it', function () {
        Sleep::fake();
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n");
        $other = createStack($this, "services:\n  web:\n    image: nginx\n", 'other');
        $old = stackNode($this->team, $this->cluster, 'old', '203.0.113.11', stackNodeCapabilities());
        Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => stackNodeCapabilities([ReconcileNodeClusterNetwork::STACK_RULES_CAPABILITY])]);
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $services['web']->nodes()->updateExistingPivot($this->node->id, ['container_ip' => '10.250.1.2', 'next_container_ip' => '10.250.1.3']);
        $services['db']->nodes()->updateExistingPivot($this->node->id, ['container_ip' => '10.250.1.4']);
        $other->workloads()->sole()->nodes()->updateExistingPivot($this->node->id, ['container_ip' => '10.250.1.9']);
        $firewall = collect();
        Http::fake(function (Request $request) use ($firewall) {
            $command = Str::afterLast($request->url(), '/');
            $data = $request->data();
            if ($command === 'network.firewall.reconcile') {
                $firewall->put($data['server_id'], $data);
            }

            return Http::response(['command_id' => $data['command_id'] ?? '', 'observed_at_unix_ms' => 1, ...match ($command) {
                'network.wireguard.reconcile' => ['rollback_cancelled' => true, 'public_key' => 'key', 'listen_port' => $data['listen_port'], 'applied_revision' => $data['revision'], 'drifted' => false, 'peers' => $data['peers']],
                'network.firewall.reconcile' => ['rollback_cancelled' => true, 'applied_revision' => $data['revision'], 'configuration_hash' => 'hash', 'drifted' => false, 'table' => 'coolify_cluster', 'ingress_enforced' => true],
                'discovery.corrosion.reconcile' => ['version' => 'v1.0.0', 'member_state' => 'joining', 'endpoint_count' => 0],
                'discovery.corrosion.inspect' => ['version' => 'v1.0.0', 'member_state' => 'converged', 'endpoint_count' => 0, 'alive_member_count' => 1],
                'ingress.reconcile' => ['enabled' => $data['enabled'], 'caddy_version' => $data['caddy_version'], 'active' => $data['enabled'], 'revision' => $data['revision'], 'route_count' => count($data['routes']), 'name_count' => count($data['names'])],
                default => [],
            }]);
        });

        ReconcileNodeClusterNetwork::run($this->cluster->refresh(), $this->user);

        expect($firewall[$this->node->uuid]['stack_rules'])->toBe([
            ['source_ip' => '10.250.1.2', 'destination_ip' => '10.250.1.4'],
            ['source_ip' => '10.250.1.3', 'destination_ip' => '10.250.1.4'],
            ['source_ip' => '10.250.1.4', 'destination_ip' => '10.250.1.2'],
            ['source_ip' => '10.250.1.4', 'destination_ip' => '10.250.1.3'],
        ])->and($firewall[$old->uuid])->not->toHaveKey('stack_rules');
    });

    it('waits for the network revision when a stack service moves', function () {
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n");

        expect($stack->workloads()->sole()->receivesClusterTraffic())->toBeTrue()
            ->and(NodeWorkload::factory()->create(['team_id' => $this->team->id])->receivesClusterTraffic())->toBeFalse();
    });
});

/**
 * Fakes Flux for stack deploys: each deploy starts a container that the inventory reports with
 * the state of its service in $states.
 *
 * @param  array<string, array{state: string, health?: ?string, exit_code?: ?int}>  $states
 */
function fakeStackFlux(NodeStack $stack, array $states, ArrayObject $deploys): void
{
    $containers = new ArrayObject;
    Http::fake(function (Request $request) use ($stack, $states, $deploys, $containers) {
        $data = $request->data();
        if (str_ends_with($request->url(), '/v1/commands/workload.deploy')) {
            $workload = NodeWorkload::query()->where('node_stack_id', $stack->id)->get()
                ->first(fn (NodeWorkload $workload): bool => str_starts_with($data['name'], 'coolify-'.$workload->uuid));
            $deploys[] = ['service' => $workload->stack_service, 'request' => $data];
            $state = $states[$workload->stack_service];
            $containers[$data['name']] = [
                'runtime_id' => 'runtime-'.$workload->stack_service.'-'.count($deploys),
                'name' => $data['name'],
                'image' => $data['image'],
                'state' => $state['state'],
                'health_status' => $state['health'] ?? null,
                'exit_code' => $state['exit_code'] ?? null,
                'restart_count' => 0,
                'ports' => [],
                'labels' => $data['labels'],
            ];

            return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000, 'runtime_id' => $containers[$data['name']]['runtime_id'], 'name' => $data['name'], 'image' => $data['image']]);
        }
        if (str_ends_with($request->url(), '/v1/commands/container.list')) {
            return Http::response(['command_id' => 'inventory', 'observed_at_unix_ms' => 1_700_000_000_000 + count($deploys), 'containers' => array_values($containers->getArrayCopy())]);
        }

        return Http::response(['command_id' => $data['command_id'] ?? 'x', 'observed_at_unix_ms' => 1]);
    });
}

/** An address that is already allowed: the deploy order tests do not run the network revision. */
function prepareStackForDeploy(NodeStack $stack, Node $node): void
{
    $stack->workloads()->get()->each(fn (NodeWorkload $workload, int $index) => $workload->nodes()->updateExistingPivot($node->id, ['container_ip' => '10.250.1.'.($index + 2)]));
}

describe('deploy order', function () {
    beforeEach(function () {
        Sleep::fake(syncWithCarbon: true);
    });

    it('deploys the services in depends_on order with the search domain and files', function () {
        $stack = createStack($this);
        prepareStackForDeploy($stack, $this->node);
        $deploys = new ArrayObject;
        fakeStackFlux($stack, [
            'postgres' => ['state' => 'running', 'health' => 'healthy'],
            'migrate' => ['state' => 'exited', 'exit_code' => 0],
            'app' => ['state' => 'running'],
        ], $deploys);

        (new DeployNodeStackJob($stack->id, $this->user->id))->handle();

        expect($stack->refresh()->deploy_status)->toBe('deployed')
            ->and(collect($deploys)->pluck('service')->all())->toBe(['postgres', 'migrate', 'app'])
            ->and(collect($deploys)->every(fn (array $deploy): bool => $deploy['request']['dns_search'] === ['shop.coolify.internal']))->toBeTrue()
            ->and($deploys[0]['request']['health_check']['command'])->toBe('pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}')
            ->and($deploys[1]['request']['restart_policy'])->toBe('no')
            ->and($deploys[2]['request']['files'][0]['path'])->toBe('/app/config.yml')
            ->and($deploys[0]['request'])->not->toHaveKey('files');
    });

    it('runs the exec form of a Compose health check without a shell, only on a Sentinel that supports it', function () {
        // An image without /bin/sh (distroless) must keep its health check: the CMD form is sent as a JSON array.
        $compose = str_replace('test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]', 'test: ["CMD", "pg_isready", "-d", "${POSTGRES_DB:-appdb}"]', stackCompose());
        $stack = createStack($this, $compose);
        $postgres = $stack->workloads()->where('stack_service', 'postgres')->sole();
        expect($postgres->stack_health_check['command'])->toBe('["pg_isready","-d","appdb"]');

        prepareStackForDeploy($stack, $this->node);
        $deploys = new ArrayObject;
        $states = ['postgres' => ['state' => 'running', 'health' => 'healthy'], 'migrate' => ['state' => 'exited', 'exit_code' => 0], 'app' => ['state' => 'running']];
        fakeStackFlux($stack, $states, $deploys);
        (new DeployNodeStackJob($stack->id, $this->user->id))->handle();

        expect($stack->refresh()->deploy_error)->toContain('does not support workload.healthcheck.exec.v1. Upgrade Sentinel')
            ->and($deploys)->toHaveCount(0);

        Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => stackNodeCapabilities(['workload.healthcheck.exec.v1'])]);
        (new DeployNodeStackJob($stack->id, $this->user->id))->handle();

        expect($stack->refresh()->deploy_status)->toBe('deployed')
            ->and($deploys[0]['request']['health_check']['command'])->toBe('["pg_isready","-d","appdb"]');
    });

    it('stops at a dependency that does not get healthy and names the services that wait for it', function () {
        $stack = createStack($this);
        prepareStackForDeploy($stack, $this->node);
        $deploys = new ArrayObject;
        fakeStackFlux($stack, ['postgres' => ['state' => 'running', 'health' => 'unhealthy'], 'migrate' => ['state' => 'exited', 'exit_code' => 0], 'app' => ['state' => 'running']], $deploys);

        (new DeployNodeStackJob($stack->id, $this->user->id))->handle();

        $stack->refresh();
        expect($stack->deploy_status)->toBe('failed')
            ->and($stack->deploy_error)->toContain('The service postgres did not become healthy')
            ->and($stack->deploy_error)->toContain('Not deployed: app, migrate.')
            ->and(collect($deploys)->pluck('service')->all())->toBe(['postgres']);
    });

    it('fails when a one-time service exits with an error', function () {
        $stack = createStack($this);
        prepareStackForDeploy($stack, $this->node);
        $deploys = new ArrayObject;
        fakeStackFlux($stack, ['postgres' => ['state' => 'running', 'health' => 'healthy'], 'migrate' => ['state' => 'exited', 'exit_code' => 1], 'app' => ['state' => 'running']], $deploys);

        (new DeployNodeStackJob($stack->id, $this->user->id))->handle();

        expect($stack->refresh()->deploy_error)->toContain('The service migrate did not complete successfully')
            ->and(collect($deploys)->pluck('service')->all())->toBe(['postgres', 'migrate']);
    });

    it('does not start a one-time container again after it ran', function () {
        $stack = createStack($this);
        $migrate = $stack->workloads()->where('stack_service', 'migrate')->sole();
        $migrate->update(['desired_state' => 'running']);
        NodeContainer::factory()->create([
            'node_id' => $this->node->id,
            'node_workload_id' => $migrate->id,
            'node_workload_revision_id' => $migrate->revisions()->sole()->id,
            'name' => 'coolify-'.$migrate->uuid.'-main',
            'state' => 'exited',
            'exit_code' => 0,
            'is_managed' => true,
        ]);

        ReconcileNodeWorkloads::run($this->node);

        expect(NodeOperation::query()->where('node_workload_id', $migrate->id)->exists())->toBeFalse();
    });

    it('refuses to deploy a stack service on a Node without the search domain', function () {
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n");
        Cache::put($this->node->cacheKey(), ['capabilities' => array_values(array_diff(stackNodeCapabilities(), [DispatchWorkloadDeployment::DNS_SEARCH_CAPABILITY]))]);
        Http::fake();

        (new DeployNodeStackJob($stack->id, $this->user->id))->handle();

        expect($stack->refresh()->deploy_error)->toContain('does not support workload.dns_search.v1');
        Http::assertNothingSent();
    });
});

describe('authorization', function () {
    it('lets an admin deploy and delete a stack from its page', function () {
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n");

        $this->get(route('project.cluster-stack.show', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid]))
            ->assertSuccessful()
            ->assertSee('docker.io/library/nginx')
            ->assertSee('shop.coolify.internal');
        $this->get(route('project.cluster-stack.danger', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid]))
            ->assertSuccessful()
            ->assertSee('Delete stack');

        Livewire::test(ClusterStackShow::class, ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid])
            ->call('deploy')
            ->call('delete');

        Queue::assertPushed(DeployNodeStackJob::class, fn (DeployNodeStackJob $job): bool => $job->stackId === $stack->id);
        Queue::assertPushed(DeleteNodeStackJob::class, fn (DeleteNodeStackJob $job): bool => $job->stackId === $stack->id);
    });

    it('lets a member view a stack but not deploy, delete, or create one', function () {
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n");
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);
        Cache::flush();
        $parameters = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid];

        $this->get(route('project.cluster-stack.danger', $parameters))->assertSuccessful()->assertDontSee('Delete stack');
        Livewire::test(ClusterStackShow::class, $parameters)->call('deploy')->assertForbidden();
        Livewire::test(ClusterStackShow::class, $parameters)->call('delete')->assertForbidden();
        $this->get(route('project.resource.create', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'type' => 'one-click-service-whoogle',
            'cluster' => $this->cluster->uuid,
        ]))->assertForbidden();

        Queue::assertNotPushed(DeployNodeStackJob::class);
        Queue::assertNotPushed(DeleteNodeStackJob::class);
        expect(NodeStack::query()->count())->toBe(1);
    });

    it('hides the stack of another team', function () {
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n");
        $other = User::factory()->create();
        $otherTeam = $other->teams()->firstOrFail();
        $otherTeam->update(['show_boarding' => false]);
        $this->team->members()->attach($other, ['role' => 'member']);
        $this->actingAs($other);
        session(['currentTeam' => $otherTeam]);
        Cache::flush();

        $this->get(route('project.cluster-stack.show', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid]))
            ->assertNotFound();
    });

    it('creates a stack from a one-click service on a cluster', function () {
        Queue::fake();

        $response = $this->get(route('project.resource.create', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'type' => 'one-click-service-n8n-with-postgresql',
            'cluster' => $this->cluster->uuid,
        ]));

        $stack = NodeStack::query()->sole();
        $response->assertRedirect(route('project.cluster-stack.show', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid]));
        expect($stack->template)->toBe('n8n-with-postgresql')
            ->and($stack->workloads()->count())->toBe(3);
    });

    it('deletes every service of a stack and then the stack', function () {
        Sleep::fake();
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n  db:\n    image: postgres\n");
        Http::fake(function (Request $request) {
            $data = $request->data();
            if (str_ends_with($request->url(), '/v1/commands/workload.lifecycle')) {
                return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => 1_700_000_000_000, 'name' => $data['name'], 'action' => $data['action']]);
            }

            return Http::response(['command_id' => 'inventory', 'observed_at_unix_ms' => 1_700_000_000_100, 'containers' => [], 'volumes' => []]);
        });

        (new DeleteNodeStackJob($stack->id, $this->user->id))->handle();

        expect(NodeStack::query()->count())->toBe(0)
            ->and(NodeWorkload::query()->count())->toBe(0);
    });
});

/** A stack whose services share a volume (decision 0024): init writes, app uses, reader reads only. */
function sharedVolumeCompose(): string
{
    return <<<'YAML'
services:
  init:
    image: busybox
    command: ["sh", "-c", "echo ok > /data/ready"]
    volumes:
      - data:/data
  reader:
    image: nginx
    volumes:
      - data:/usr/share/nginx/html:ro
  app:
    image: nginx
    volumes:
      - data:/data
      - app-cache:/cache
    depends_on:
      init:
        condition: service_completed_successfully
      reader:
        condition: service_started
  web:
    image: nginx
    volumes:
      - web-data:/data
YAML;
}

/** @return list<string> */
function sharedVolumeCapabilities(): array
{
    return stackNodeCapabilities([
        'volume.delete.v1', 'volume.serve.v1', 'volume.receive.v1', 'volume.transfer.v1', 'volume.verify.v1',
        'workload.stop_timeout.v1', 'volume.backup.v1', 'volume.restore.v1',
    ]);
}

/**
 * Deploys the stack revisions (with their mounts) on the Node, pins the volumes there, and records a
 * running container for each service except init, which ran once. Fakes Flux with fakeSharedVolumeFlux().
 *
 * @return ArrayObject<string, array<string, array<string, mixed>>> Containers by server UUID and name.
 */
function deploySharedStack(NodeStack $stack, Node $node, ?ArrayObject $log = null): ArrayObject
{
    $containers = new ArrayObject;
    foreach ($stack->workloads()->get() as $index => $workload) {
        $revision = PrepareNodeWorkloadRevision::run($workload);
        $workload->update(['desired_state' => 'running']);
        $workload->nodes()->updateExistingPivot($node->id, ['container_ip' => '10.250.1.'.($index + 2)]);
        $name = 'coolify-'.$workload->uuid.'-main';
        $containers[$node->uuid] = [...($containers[$node->uuid] ?? []), $name => [
            'runtime_id' => 'runtime-'.$workload->stack_service,
            'name' => $name,
            'image' => $revision->image,
            'state' => $workload->stack_service === 'init' ? 'exited' : 'running',
            'exit_code' => $workload->stack_service === 'init' ? 0 : null,
            'health_status' => null,
            'restart_count' => 0,
            'ports' => [],
            'labels' => [
                'coolify.managed' => 'true',
                'coolify.instance' => 'instance-test',
                'coolify.workload' => $workload->uuid,
                'coolify.revision' => $revision->uuid,
                'coolify.component' => 'main',
            ],
        ]];
    }
    NodeVolume::query()->where('team_id', $stack->team_id)->update(['node_id' => $node->id]);
    fakeSharedVolumeFlux($stack, $containers, $log ?? new ArrayObject);
    FetchContainersAction::run($node);

    return $containers;
}

/**
 * Fakes Flux for moves and restores of shared volumes: containers per server, lifecycle actions,
 * the volume transfer, the restore steps, and volume deletion. Each command goes to $log.
 *
 * @param  ArrayObject<string, array<string, array<string, mixed>>>  $containers
 */
function fakeSharedVolumeFlux(NodeStack $stack, ArrayObject $containers, ArrayObject $log): void
{
    Http::fake(function (Request $request) use ($stack, $containers, $log) {
        $data = $request->data();
        $command = Str::afterLast($request->url(), '/');
        $server = $data['server_id'] ?? '';
        $service = fn (string $name): ?string => NodeWorkload::query()->where('node_stack_id', $stack->id)->get()
            ->first(fn (NodeWorkload $workload): bool => str_starts_with($name, 'coolify-'.$workload->uuid))?->stack_service;
        $now = 1_700_000_000_000 + count($log);
        switch ($command) {
            case 'workload.deploy':
                $log[] = "deploy {$service($data['name'])} on {$server}";
                $exits = $service($data['name']) === 'init';
                $containers[$server] = [...($containers[$server] ?? []), $data['name'] => [
                    'runtime_id' => 'runtime-'.Str::random(6), 'name' => $data['name'], 'image' => $data['image'],
                    'state' => $exits ? 'exited' : 'running', 'exit_code' => $exits ? 0 : null, 'health_status' => null,
                    'restart_count' => 0, 'ports' => [], 'labels' => $data['labels'],
                ]];

                return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => $now, 'runtime_id' => $containers[$server][$data['name']]['runtime_id'], 'name' => $data['name'], 'image' => $data['image']]);
            case 'workload.lifecycle':
                $log[] = "{$data['action']} {$service($data['name'])} on {$server}";
                $current = $containers[$server] ?? [];
                if ($data['action'] === 'remove') {
                    unset($current[$data['name']]);
                } elseif (isset($current[$data['name']])) {
                    $current[$data['name']]['state'] = $data['action'] === 'stop' ? 'exited' : 'running';
                }
                $containers[$server] = $current;

                return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => $now, 'name' => $data['name'], 'action' => $data['action']]);
            case 'container.list':
                return Http::response(['command_id' => 'inventory', 'observed_at_unix_ms' => $now, 'containers' => array_values($containers[$server] ?? [])]);
            case 'volume.serve':
                if (! ($data['stop'] ?? false)) {
                    $log[] = 'serve '.implode(',', $data['names']);
                }

                return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => $now, 'address' => '10.240.0.1', 'port' => 8730, 'volumes' => array_map(fn (string $name): array => ['name' => $name, 'used_bytes' => 10], $data['names'] ?? [])]);
            case 'volume.receive':
                $log[] = "receive {$data['pass']}";

                return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => $now, 'state' => 'succeeded', 'bytes_total' => 10, 'bytes_done' => 10]);
            case 'volume.restore':
                $log[] = "restore {$data['step']}";

                return Http::response(['command_id' => $data['command_id'], 'state' => 'succeeded', 'bytes_total' => 10, 'bytes_done' => 10]);
            case 'volume.delete':
                $log[] = 'delete '.implode(',', $data['names']);

                return Http::response(['command_id' => $data['command_id'], 'observed_at_unix_ms' => $now, 'deleted' => $data['names'], 'missing' => []]);
        }
        $log[] = "unexpected {$command}";

        return Http::response('unexpected', 500);
    });
}

describe('shared volumes', function () {
    beforeEach(function () {
        Sleep::fake(syncWithCarbon: true);
        Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => sharedVolumeCapabilities()]);
    });

    it('maps a volume that two services mount to one stack volume with the mode of each service', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $data = $stack->volumes()->sole();

        expect($data->name)->toBe('data')
            ->and($data->node_workload_id)->toBeNull()
            ->and($data->mounts()->get()->mapWithKeys(fn ($mount): array => [$services->firstWhere('id', $mount->node_workload_id)->stack_service => [$mount->mount_path, $mount->read_only]])->sortKeys()->all())
            ->toBe(['app' => ['/data', false], 'init' => ['/data', false], 'reader' => ['/usr/share/nginx/html', true]])
            ->and($services['app']->volumes()->pluck('mount_path', 'name')->all())->toBe(['app-cache' => '/cache'])
            ->and($services['web']->volumes()->pluck('mount_path', 'name')->all())->toBe(['web-data' => '/data'])
            ->and($services['web']->stackVolumes()->exists())->toBeFalse()
            ->and(collect($services['app']->volumeMounts())->map(fn (array $mount): array => [$mount['container_path'], $mount['read_only']])->all())
            ->toBe([['/cache', false], ['/data', false]])
            ->and($services['reader']->volumeMounts())->toBe([[
                'volume_uuid' => $data->uuid,
                'volume_name' => 'coolify-vol-'.$data->uuid,
                'container_path' => '/usr/share/nginx/html',
                'read_only' => true,
            ]])
            ->and($services['reader']->description)->toBeNull()
            ->and($services['app']->volumeGroup()->pluck('stack_service')->all())->toBe(['app', 'init', 'reader'])
            ->and($services['web']->volumeGroup()->pluck('stack_service')->all())->toBe(['web']);
    });

    it('mounts the volumes of another service with volumes_from, read-only with :ro', function () {
        $stack = createStack($this, <<<'YAML'
services:
  app:
    image: nginx
    volumes:
      - ./uploads:/app/uploads
      - cache:/cache
  worker:
    image: nginx
    volumes:
      - ./uploads/:/srv/uploads:ro
  reader:
    image: nginx
    volumes_from:
      - app:ro
  peer:
    image: nginx
    volumes_from: [worker]
YAML);
        $mounts = fn (string $service): array => collect($stack->workloads()->where('stack_service', $service)->sole()->volumeMounts())
            ->map(fn (array $mount): string => $mount['container_path'].($mount['read_only'] ? ':ro' : ''))->all();

        // The relative bind ./uploads is one volume for app and worker (`./uploads/` is the same directory).
        expect($stack->volumes()->pluck('name')->all())->toBe(['uploads', 'cache'])
            ->and($mounts('app'))->toBe(['/app/uploads', '/cache'])
            ->and($mounts('worker'))->toBe(['/srv/uploads:ro'])
            ->and($mounts('reader'))->toBe(['/app/uploads:ro', '/cache:ro'])
            ->and($mounts('peer'))->toBe(['/srv/uploads:ro'])
            ->and(NodeVolume::query()->whereNotNull('node_workload_id')->count())->toBe(0);
    });

    it('rejects volumes_from of a container or of a service that the stack does not have', function (string $entry, string $reason) {
        $yaml = NodeStackCompose::parse("services:\n  web:\n    image: nginx\n    volumes_from: ['{$entry}']\n");

        expect(NodeStackCompose::unavailableReason($yaml))->toBe($reason);
    })->with([
        'container' => ['container:other', 'The service web mounts the volumes of the container other (volumes_from), which is not a service of this stack.'],
        'missing service' => ['db:ro', 'The service web mounts the volumes of db (volumes_from), which is not a service of this stack.'],
    ]);

    it('pins every service of a stack volume to its server and blocks a deploy on another server', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $other = stackNode($this->team, $this->cluster, 'node-b', '203.0.113.20', sharedVolumeCapabilities());
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $stack->volumes()->sole()->update(['node_id' => $this->node->id]);
        $services['reader']->nodes()->sync([$other->id]);
        $revision = PrepareNodeWorkloadRevision::run($services['reader']);

        expect(fn () => CreateDeploymentOperation::run($other, $revision, $this->user))
            ->toThrow(RuntimeException::class, 'The service reader shares the volume data with init, app on server node-a, so it runs only there. Move the service to move all of them.');
        expect(NodeOperation::query()->where('node_workload_id', $services['reader']->id)->exists())->toBeFalse();
    });

    it('deploys a service with a writable stack volume stop-then-start, and a read-only one rolling', function () {
        $stack = createStack($this, sharedVolumeCompose());
        Cache::put($this->node->cacheKey(), ['status' => 'connected', 'last_heartbeat_at' => now()->toIso8601String(), 'capabilities' => [...sharedVolumeCapabilities(), DispatchWorkloadDeployment::ROLLING_CAPABILITY]]);
        deploySharedStack($stack, $this->node);
        $plan = function (string $service) use ($stack): array {
            $workload = $stack->workloads()->where('stack_service', $service)->sole();
            $operation = NodeOperation::factory()->create([
                'node_id' => $this->node->id,
                'node_workload_id' => $workload->id,
                'node_workload_revision_id' => $workload->revisions()->latest('id')->first()->id,
                'command_type' => 'workload.deploy.v1',
                'request' => [],
            ]);

            return DispatchWorkloadDeployment::containerPlan($operation, $workload->volumeMounts());
        };

        expect($plan('app')['replaces'])->toBeNull()
            ->and($plan('reader')['replaces'])->not->toBeNull();
    });

    it('moves the whole volume group with one copy of each volume and starts it in depends_on order', function () {
        ApplyNodeClusterNetworkRevision::shouldRun()->andReturnNull();
        QueueNodeClusterNetworkRevision::shouldRun()->andReturn(true);
        $stack = createStack($this, sharedVolumeCompose());
        $target = stackNode($this->team, $this->cluster, 'node-b', '203.0.113.20', sharedVolumeCapabilities());
        $log = new ArrayObject;
        deploySharedStack($stack, $this->node, $log);
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $volumes = NodeVolume::query()->where('team_id', $this->team->id)->get()->keyBy('name');

        $operation = CreateMoveOperation::run($this->node, $target, $services['reader']->revisions()->latest('id')->first(), $this->user);
        (new MoveNodeWorkloadJob($operation->id))->handle();

        $a = $this->node->uuid;
        $b = $target->uuid;
        expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
            ->and(collect($operation->request['group'])->pluck('workload_uuid')->all())->toBe([$services['init']->uuid, $services['app']->uuid])
            ->and($log->getArrayCopy())->toBe([
                'serve coolify-vol-'.$volumes['app-cache']->uuid.',coolify-vol-'.$volumes['data']->uuid,
                'receive warm',
                "stop reader on {$a}",
                "stop app on {$a}",
                'receive final',
                'receive verify',
                "deploy init on {$b}",
                "deploy reader on {$b}",
                "deploy app on {$b}",
                "remove reader on {$a}",
                "remove init on {$a}",
                "remove app on {$a}",
            ])
            ->and($volumes['data']->refresh()->node_id)->toBe($target->id)
            ->and($volumes['app-cache']->refresh()->node_id)->toBe($target->id)
            ->and($volumes['web-data']->refresh()->node_id)->toBe($this->node->id)
            ->and($services->only(['init', 'reader', 'app'])->every(fn (NodeWorkload $workload): bool => $workload->nodes()->pluck('nodes.id')->all() === [$target->id]))->toBeTrue()
            ->and($services['web']->nodes()->pluck('nodes.id')->all())->toBe([$this->node->id]);
    });

    it('blocks deploys of every service of the group while the group moves', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $target = stackNode($this->team, $this->cluster, 'node-b', '203.0.113.20', sharedVolumeCapabilities());
        deploySharedStack($stack, $this->node);
        $services = $stack->workloads()->get()->keyBy('stack_service');
        CreateMoveOperation::run($this->node, $target, $services['app']->revisions()->latest('id')->first(), $this->user);

        expect($services['reader']->hasActiveMove())->toBeTrue()
            ->and($services['web']->hasActiveMove())->toBeFalse()
            ->and(fn () => CreateDeploymentOperation::run($this->node, $services['init']->revisions()->latest('id')->first(), $this->user))
            ->toThrow(RuntimeException::class, 'moving with its volumes')
            ->and(fn () => CreateMoveOperation::run($this->node, $target, $services['reader']->revisions()->latest('id')->first(), $this->user))
            ->toThrow(RuntimeException::class, 'A service of this volume group already has an active move.');
    });

    it('restores a stack volume by stopping every service that mounts it and starting them in depends_on order', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $log = new ArrayObject;
        deploySharedStack($stack, $this->node, $log);
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $data = $stack->volumes()->sole();
        $s3 = S3Storage::query()->create(['team_id' => $this->team->id, 'name' => 'Backups', 'region' => 'us-east-1', 'key' => 'key', 'secret' => 'secret', 'bucket' => 'backups', 'endpoint' => 'https://8.8.8.8', 'is_usable' => true]);
        $backup = $data->scheduledBackups()->create(['team_id' => $this->team->id, 's3_storage_id' => $s3->id, 'frequency' => '0 0 * * *', 'enabled' => true, 'save_s3' => true, 'disable_local_backup' => true]);
        $execution = $backup->executions()->create(['status' => 'success', 'filename' => 'volume-backups/x.tar.zst', 'sha256' => str_repeat('a', 64), 'size' => 10, 's3_storage_id' => $s3->id]);

        $operation = RestoreNodeVolume::queue($data, $execution, $this->node, $this->user, $services['app']);
        (new MoveNodeWorkloadJob($operation->id))->handle();

        $a = $this->node->uuid;
        expect($operation->refresh()->status)->toBe(NodeOperationStatus::SUCCEEDED)
            ->and($log->getArrayCopy())->toBe([
                'restore download',
                "stop app on {$a}",
                "stop reader on {$a}",
                'restore switch',
                "start reader on {$a}",
                "start app on {$a}",
            ]);
    });

    it('keeps a backup of a shared volume from stopping a container', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $data = $stack->volumes()->sole();
        $backup = $data->scheduledBackups()->create(['team_id' => $this->team->id, 'frequency' => '0 0 * * *', 'stop_during_backup' => true]);

        expect(fn () => BackupNodeVolume::ensureCanStopDuringBackup($backup, $data))->toThrow(RuntimeException::class, BackupNodeVolume::SHARED_STOP_ERROR);
    });

    it('keeps a stack volume while a service mounts it, and deletes it with the stack', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $log = new ArrayObject;
        deploySharedStack($stack, $this->node, $log);
        $services = $stack->workloads()->get()->keyBy('stack_service');
        $data = $stack->volumes()->sole();

        (new DeleteNodeWorkloadJob($services['reader']->id, $this->user->id))->handle();

        expect($data->refresh()->mountingWorkloads()->pluck('stack_service')->all())->toBe(['init', 'app'])
            ->and(fn () => DeleteNodeVolumeData::run($data))->toThrow(RuntimeException::class, 'The services init, app still mount this volume.')
            ->and(RemoveNodeVolume::run($data, $services['init']))->toBe('detached')
            ->and($data->mountingWorkloads()->pluck('stack_service')->all())->toBe(['app']);

        $log->exchangeArray([]);
        (new DeleteNodeStackJob($stack->id, $this->user->id))->handle();

        expect(NodeStack::query()->count())->toBe(0)
            ->and(NodeVolume::query()->count())->toBe(0)
            ->and($log->getArrayCopy())->toContain('delete coolify-vol-'.$data->uuid);
    });

    it('deletes an unmounted stack volume from the stack page, which a member cannot do', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $data = $stack->volumes()->sole();
        $data->mounts()->delete();
        $parameters = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid];
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);

        $this->actingAs($member);
        Cache::flush();
        Livewire::test(ClusterStackShow::class, $parameters)->call('deleteVolume', $data->uuid)->assertForbidden();
        expect($data->fresh())->not->toBeNull();

        $this->actingAs($this->user);
        Cache::flush();
        $this->get(route('project.cluster-stack.storages', $parameters))->assertSuccessful()->assertSee('Shared volumes')->assertSee('No service mounts this volume.');
        Livewire::test(ClusterStackShow::class, $parameters)->call('deleteVolume', $data->uuid)->assertDispatched('success');
        expect($data->fresh())->toBeNull();
    });

    it('shows Shared with on the storage page of a service and keeps members from removing it', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $app = $stack->workloads()->where('stack_service', 'app')->sole();
        $data = $stack->volumes()->sole();
        $parameters = ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'workload_uuid' => $app->uuid];

        $this->get(route('project.cluster-application.storage', $parameters))->assertSuccessful()->assertSee('Shared with: init, reader');

        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => 'member']);
        $this->actingAs($member);
        Cache::flush();
        Livewire::test(ClusterApplicationShow::class, $parameters)->call('removeVolume', $data->uuid)->assertNotDispatched('success');
        expect($data->mounts()->count())->toBe(3);
    });

    it('hides the stack volumes of another team', function () {
        $stack = createStack($this, sharedVolumeCompose());
        $other = User::factory()->create();
        $otherTeam = $other->teams()->firstOrFail();
        $otherTeam->update(['show_boarding' => false]);
        $this->actingAs($other);
        session(['currentTeam' => $otherTeam]);
        Cache::flush();

        $this->get(route('project.cluster-stack.show', ['project_uuid' => $this->project->uuid, 'environment_uuid' => $this->environment->uuid, 'stack_uuid' => $stack->uuid]))
            ->assertNotFound();
    });
});

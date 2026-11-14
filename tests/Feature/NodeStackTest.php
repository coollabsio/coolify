<?php

use App\Actions\Node\BuildNodeClusterIngressRoutes;
use App\Actions\Node\CreateClusterStack;
use App\Actions\Node\DispatchWorkloadDeployment;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ReconcileNodeWorkloads;
use App\Jobs\DeleteNodeStackJob;
use App\Jobs\DeployNodeStackJob;
use App\Livewire\Project\ClusterStack\Show as ClusterStackShow;
use App\Livewire\Project\New\Select;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeContainer;
use App\Models\NodeOperation;
use App\Models\NodeStack;
use App\Models\NodeWorkload;
use App\Models\PrivateKey;
use App\Models\Project;
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
        $reasons = collect(glob(base_path('templates/compose/*.{yaml,yml}'), GLOB_BRACE))
            ->mapWithKeys(fn (string $path): array => [pathinfo($path, PATHINFO_FILENAME) => NodeStackCompose::unavailableReason(NodeStackCompose::parse(file_get_contents($path)))]);

        // Decision 0023 counts 316 runnable templates. 20 more share a volume between two
        // services (or use volumes_from), which a Node volume cannot do (decision 0007).
        expect($reasons->filter(fn (?string $reason): bool => $reason === null)->count())->toBe(296)
            ->and($reasons['twenty'])->toBe('The services twenty and worker share the volume twenty-local-storage.')
            ->and($reasons['portainer'])->toBe('The service portainer mounts the Docker socket.')
            ->and($reasons['gitea'])->toContain('publishes ports')
            ->and($reasons['n8n-with-postgresql'])->toBeNull()
            ->and($reasons['rallly'])->toBeNull();
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
        'ports' => ["    ports: ['8080:80']\n", 'publishes ports (decision 0016)'],
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
        $stack = createStack($this, "services:\n  web:\n    image: nginx\n  rallly_db:\n    image: postgres\n");
        $services = $stack->workloads()->get()->keyBy('stack_service');

        $names = collect(BuildNodeClusterIngressRoutes::names($this->cluster));

        expect($names->where('namespace', 'default')->pluck('workload_id')->sort()->values()->all())->toBe($services->pluck('uuid')->sort()->values()->all())
            ->and($names->where('namespace', 'shop')->values()->all())->toBe([
                ['name' => 'web', 'workload_id' => $services['web']->uuid, 'namespace' => 'shop'],
                ['name' => 'rallly_db', 'workload_id' => $services['rallly_db']->uuid, 'namespace' => 'shop'],
            ]);

        // A Node without stack names support would reject the whole table for a name with `_`.
        Cache::put($this->node->cacheKey(), ['capabilities' => stackNodeCapabilities()]);
        $old = stackNode($this->team, $this->cluster, 'old', '203.0.113.11', array_diff(stackNodeCapabilities(), ['workload.dns_search.v1']));
        expect(collect(BuildNodeClusterIngressRoutes::names($this->cluster))->where('namespace', 'shop')->pluck('name')->all())->toBe(['web']);
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
            ->assertSee('shop.coolify.internal')
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

        $this->get(route('project.cluster-stack.show', $parameters))->assertSuccessful()->assertDontSee('Delete stack');
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

<?php

use App\Actions\Development\ConfigureDevelopmentQemuHost;
use App\Actions\Development\ManageDevelopmentQemuVm;
use App\Actions\Development\SeedDevelopmentQemuServer;
use App\Actions\Development\StartDevelopmentQemuVm;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\ReconcileNodeClusterNetwork;
use App\Actions\Node\ValidateNode;
use App\Actions\Sentinel\PingFluxConnection;
use App\Console\Commands\BootstrapDevelopmentQemuNodesCommand;
use App\Console\Commands\ManageDevelopmentQemuVmCommand;
use App\Console\Commands\SeedDevelopmentQemuServerCommand;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\Server;
use App\Support\ValidationPatterns;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.env' => 'local']);
    $this->seed([UserSeeder::class, TeamSeeder::class, PrivateKeySeeder::class]);
});

it('registers the interactive qemu command', function () {
    expect(Artisan::all())
        ->toHaveKey('dev:qemu')
        ->toHaveKey('dev:qemu:seed')
        ->toHaveKey('dev:qemu:bootstrap-nodes')
        ->and(Artisan::all()['dev:qemu'])->toBeInstanceOf(ManageDevelopmentQemuVmCommand::class)
        ->and(Artisan::all()['dev:qemu']->getDefinition()->getArgument('profiles')->isArray())->toBeTrue()
        ->and(Artisan::all()['dev:qemu:seed'])->toBeInstanceOf(SeedDevelopmentQemuServerCommand::class)
        ->and(Artisan::all()['dev:qemu:bootstrap-nodes'])->toBeInstanceOf(BootstrapDevelopmentQemuNodesCommand::class);
});

it('requires one profile when a qemu vm is selected as localhost', function () {
    Process::fake();

    expect(Artisan::call('dev:qemu', [
        'profiles' => ['ubuntu-root', 'debian-root'],
        '--as-localhost' => true,
    ]))->toBe(Command::FAILURE);

    Process::assertNothingRan();
});

it('prevents qemu commands from running outside development', function () {
    config(['app.env' => 'production']);
    Process::fake();

    expect(Artisan::call('dev:qemu', ['profiles' => ['ubuntu-root']]))->toBe(Command::FAILURE)
        ->and(Artisan::call('dev:qemu:seed', ['profile' => 'ubuntu-root']))->toBe(Command::FAILURE);

    Process::assertNothingRan();
});

it('provides root and non-root profiles for every supported distribution', function () {
    $profiles = collect(config('development-qemu.profiles'));

    expect($profiles->keys()->all())->toBe([
        'node-worker-a',
        'node-worker-b',
        'node-onboarding',
        'ubuntu-root',
        'ubuntu-non-root',
        'debian-root',
        'debian-non-root',
        'centos-root',
        'centos-non-root',
        'alpine-root',
        'alpine-non-root',
    ])->and($profiles->pluck('ip')->unique()->count())->toBe(11)
        ->and($profiles->pluck('mac')->unique()->count())->toBe(11)
        ->and($profiles->pluck('uuid')->unique()->count())->toBe(11)
        ->and($profiles->pluck('domain')->unique()->count())->toBe(11)
        ->and($profiles->filter(fn (array $profile) => $profile['user'] === 'root')->count())->toBe(7)
        ->and($profiles->filter(fn (array $profile) => $profile['user'] !== 'root')->count())->toBe(4);
});

it('prepares the node worker template with podman and a boot-persistent docker socket', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-node-worker-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('node-worker-a');

    $userData = File::get("{$storagePath}/coolify-dev-node-worker-a-user-data.yaml");
    $cloudInit = Yaml::parse($userData);

    expect($cloudInit['packages'])->toContain('openssh-server', 'podman', 'podman-docker', 'wireguard-tools', 'nftables', 'iputils-ping', 'curl')
        ->and($cloudInit['runcmd'][0])->toContain(
            'systemctl enable --now ssh',
            'systemctl enable --now podman.socket',
            "printf 'L+ /run/docker.sock - - - - /run/podman/podman.sock\\n' > /etc/tmpfiles.d/coolify-podman-docker.conf",
            'podman info',
            'touch /etc/cloud/cloud-init.disabled',
            'poweroff',
        )
        ->and($userData)->not->toContain('docker.io', 'get.docker.com', 'docker compose version', 'coolify-flux');
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh domstate') && str_contains($process->command, 'coolify-dev-node-worker-a'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'mv ') && str_contains($process->command, 'coolify-dev-node-worker-a-prepared.qcow2'));
    Process::assertRan(fn ($process) => str_starts_with($process->command, 'virt-install ') && str_contains($process->command, '--memory 3072') && str_contains($process->command, 'readonly=on'));
    Process::assertRan(fn ($process) => str_contains($process->command, "root@192.168.122.50' ")
        && str_contains($process->command, '192.168.122.1')
        && str_contains($process->command, 'coolify-flux')
        && str_contains($process->command, 'ln -sfn /run/podman/podman.sock /run/docker.sock'));
});

it('points reused instance node workers at the instance flux gateway', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-instance-node-test-'.uniqid();
    useDevelopmentQemuInstance('main', 3, $storagePath);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.30.0.0/16\n"),
        '* dominfo *' => Process::result(output: 'exists'),
        '* domstate *' => Process::result(output: "running\n"),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('node-worker-b');

    Process::assertRan(fn ($process) => $process->command === "virsh dominfo 'coolify-dev-main--node-worker-b'");
    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'coolify-main' ssh")
        && str_contains($process->command, "'root@10.221.3.51'")
        && str_contains($process->command, "'\\''10.221.3.1'\\'' >> /etc/hosts"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh destroy'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, '192.168.122'));
});

it('removes the legacy single node worker vm before creating a node worker', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-legacy-node-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    File::ensureDirectoryExists($storagePath);
    File::put("{$storagePath}/coolify-dev-node-worker.qcow2", 'legacy data');
    File::put("{$storagePath}/coolify-dev-ubuntu-root.qcow2", 'other vm data');
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('node-worker-a');

    Process::assertRan(fn ($process) => $process->command === "virsh destroy 'coolify-dev-node-worker'");
    Process::assertRan(fn ($process) => $process->command === "virsh undefine 'coolify-dev-node-worker'");
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'coolify-dev-ubuntu-root'));
    expect(File::exists("{$storagePath}/coolify-dev-node-worker.qcow2"))->toBeFalse()
        ->and(File::get("{$storagePath}/coolify-dev-ubuntu-root.qcow2"))->toBe('other vm data');
});

it('stores vm disks in a libvirt-accessible directory', function () {
    expect(config('development-qemu.storage_path'))->toStartWith('/var/lib/libvirt/images/');
});

it('automatically configures the qemu host', function () {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'command -v')) {
            return Process::result();
        }

        if (str_contains($process->command, 'net-info')) {
            return Process::result(exitCode: 1);
        }

        if (str_contains($process->command, 'network inspect')) {
            return Process::result(output: "172.18.0.0/16\n");
        }

        if (str_contains($process->command, 'iptables -C')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    ConfigureDevelopmentQemuHost::run();

    Process::assertRan(fn ($process) => str_contains($process->command, 'command -v') && str_contains($process->command, 'xorriso'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'systemctl enable --now libvirtd'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh net-define'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh net-start'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh net-autostart'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'sysctl -w net.ipv4.ip_forward=1'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'iptables -I LIBVIRT_FWI'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'net.ipv4.conf.virbr0.route_localnet=1'));
    Process::assertRan(fn ($process) => str_contains($process->command, '-t nat -I PREROUTING') && str_contains($process->command, '--dport 8000') && str_contains($process->command, '127.0.0.1:8000'));
});

it('does not restart an active libvirt network', function () {
    Process::fake(function ($process) {
        if (str_contains($process->command, 'net-info')) {
            return Process::result(output: "Name: default\nActive:          yes\n");
        }

        if (str_contains($process->command, 'network inspect')) {
            return Process::result(output: "172.18.0.0/16\n");
        }

        return Process::result();
    });

    ConfigureDevelopmentQemuHost::run();

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh net-start'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'iptables -D LIBVIRT_FWI'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'iptables -I LIBVIRT_FWI 1'));
});

it('seeds one predefined root qemu server', function () {
    $server = SeedDevelopmentQemuServer::run('ubuntu-root');

    expect($server->uuid)->toBe('development-qemu-ubuntu-root')
        ->and($server->description)->toBe('Development QEMU virtual machine')
        ->and(Validator::make(['description' => $server->description], ['description' => ValidationPatterns::descriptionRules()])->passes())->toBeTrue()
        ->and($server->ip)->toBe('192.168.122.10')
        ->and($server->user)->toBe('root')
        ->and($server->team_id)->toBe(0)
        ->and(Server::query()->where('uuid', 'like', 'development-qemu-%')->count())->toBe(1);
});

it('uses a qemu vm as the localhost sentinel without changing its identity', function () {
    $this->seed(ServerSeeder::class);

    $server = SeedDevelopmentQemuServer::run('ubuntu-root', true, true);

    expect($server->id)->toBe(0)
        ->and($server->uuid)->toBe('localhost')
        ->and($server->name)->toBe('localhost')
        ->and($server->description)->toBe('Development QEMU virtual machine')
        ->and($server->ip)->toBe('192.168.122.10')
        ->and($server->user)->toBe('root')
        ->and($server->private_key_id)->toBe(1)
        ->and(Server::query()->count())->toBe(1);
});

it('can change the localhost qemu profile without adding a server', function () {
    $this->seed(ServerSeeder::class);
    SeedDevelopmentQemuServer::run('ubuntu-root', true, true);

    SeedDevelopmentQemuServer::run('ubuntu-non-root', true, true);

    expect(Server::query()->findOrFail(0)->ip)->toBe('192.168.122.11')
        ->and(Server::query()->findOrFail(0)->user)->toBe('coolify')
        ->and(Server::query()->count())->toBe(1);
});

it('seeds localhost through the container command', function () {
    $this->seed(ServerSeeder::class);

    expect(Artisan::call('dev:qemu:seed', [
        'profile' => 'ubuntu-root',
        '--as-localhost' => true,
    ]))->toBe(Command::SUCCESS)
        ->and(Server::query()->findOrFail(0)->ip)->toBe('192.168.122.10');
});

it('runs the node-dev stack through the instance launcher', function () {
    $jean = json_decode(File::get(base_path('jean.json')), true, flags: JSON_THROW_ON_ERROR);
    $launcher = File::get(base_path('scripts/dev'));

    expect(File::get(base_path('.env.development.example')))->toContain('DEVELOPMENT_QEMU_AUTO_START=false')
        ->and($jean['scripts']['run'])->toBe('./scripts/dev run')
        ->and(File::get(base_path('scripts/dev-stack')))->toContain('exec "$(dirname "$0")/dev" run')
        ->and($launcher)->toContain('NODE_COMPOSE_FILE="docker-compose.node-dev.yml"')
        ->and($launcher)->toContain('DEVELOPMENT_QEMU_AUTO_START')
        ->and($launcher)->toContain('NODE_WORKER_PROFILES=(node-worker-a node-worker-b)')
        ->and($launcher)->toContain('DEVELOPMENT_QEMU_COOLIFY_PORT="$(env_value "$instance" APP_PORT)"')
        ->and($launcher)->toContain('exec -T coolify php artisan dev:qemu:bootstrap-nodes');

    $compose = File::get(base_path('docker-compose.node-dev.yml'));
    expect($compose)->toContain("postgres:\n        condition: service_healthy")
        ->toContain('container_name: "${COMPOSE_PROJECT_NAME:-coolify}-flux"')
        ->toContain("aliases:\n          - coolify-flux")
        ->toContain('DEVELOPMENT_QEMU_COOLIFY_PORT: "${APP_PORT:-8000}"');
});

it('prepares the onboarding node with ssh only and points it at the instance flux gateway', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-onboarding-test-'.uniqid();
    useDevelopmentQemuInstance('main', 3, $storagePath);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.30.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('node-onboarding');

    $userData = File::get("{$storagePath}/coolify-dev-main--node-onboarding-user-data.yaml");
    $cloudInit = Yaml::parse($userData);

    expect($cloudInit['packages'])->toBe(['openssh-server', 'sudo'])
        ->and($cloudInit['runcmd'][0])->toContain('systemctl enable --now ssh', 'touch /etc/cloud/cloud-init.disabled', 'poweroff')
        ->and($userData)->not->toContain('podman', 'docker', 'wireguard');
    Process::assertRan(fn ($process) => str_contains($process->command, 'coolify-dev-node-onboarding-prepared.qcow2'));
    Process::assertRan(fn ($process) => str_contains($process->command, "'root@10.221.3.52'")
        && str_contains($process->command, "'\\''10.221.3.1'\\'' >> /etc/hosts")
        && ! str_contains($process->command, 'podman.sock'));
});

it('starts the onboarding node without seeding a server or node', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-onboarding-manage-'.uniqid()]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    ManageDevelopmentQemuVm::run(['node-onboarding', 'node-worker-a']);

    expect(Node::query()->where('uuid', 'development-qemu-node-onboarding')->exists())->toBeFalse()
        ->and(Server::query()->where('uuid', 'development-qemu-node-onboarding')->exists())->toBeFalse()
        ->and(Node::query()->where('uuid', 'development-qemu-node-worker-a')->exists())->toBeTrue();
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-node-onboarding'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'dev:qemu:seed'));
});

it('never seeds the onboarding node or uses it as localhost', function () {
    Process::fake();

    expect(fn () => SeedDevelopmentQemuServer::run('node-onboarding'))->toThrow(InvalidArgumentException::class, 'never seeded')
        ->and(fn () => ManageDevelopmentQemuVm::run('node-onboarding', true))->toThrow(InvalidArgumentException::class, 'localhost');
    Process::assertNothingRan();
});

it('tells the developer where to onboard the clean node', function () {
    config(['app.env' => 'local']);
    ManageDevelopmentQemuVm::shouldRun()->once()->with(['node-onboarding'], false, false);

    $this->artisan('dev:qemu', ['profiles' => ['node-onboarding']])
        ->expectsOutputToContain('Add it at /node-clusters/new-node')
        ->assertSuccessful();
});

it('seeds the first node worker as a separate node with the host gateway endpoint', function () {
    $node = SeedDevelopmentQemuServer::run('node-worker-a');

    expect($node)->toBeInstanceOf(Node::class)
        ->and($node->uuid)->toBe('development-qemu-node-worker-a')
        ->and($node->role->value)->toBe('worker')
        ->and($node->ip)->toBe('192.168.122.50')
        ->and($node->user)->toBe('root')
        ->and($node->sentinel_url)->toBe('http://192.168.122.1:8000')
        ->and($node->is_usable)->toBeFalse()
        ->and(Server::query()->where('uuid', $node->uuid)->exists())->toBeFalse();
});

it('keeps the other node workers and their history when a node worker is seeded again', function () {
    SeedDevelopmentQemuServer::run('node-worker-a');
    $nodeB = SeedDevelopmentQemuServer::run('node-worker-b', false);
    $workload = NodeWorkload::factory()->create(['team_id' => 0]);
    $nodeB->workloads()->attach($workload);

    SeedDevelopmentQemuServer::run('node-worker-a');

    expect(Node::query()->where('uuid', 'development-qemu-node-worker-b')->value('id'))->toBe($nodeB->id)
        ->and($nodeB->workloads()->whereKey($workload->id)->exists())->toBeTrue();
});

it('starts and seeds both node workers together', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-node-mesh-test-'.uniqid()]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    ManageDevelopmentQemuVm::run(['node-worker-a', 'node-worker-b']);

    expect(Node::query()->whereIn('uuid', [
        'development-qemu-node-worker-a',
        'development-qemu-node-worker-b',
    ])->count())->toBe(2);
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-node-worker-a'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-node-worker-b'));
});

it('keeps the localhost server and node workers when seeding each other', function () {
    $this->seed(ServerSeeder::class);
    SeedDevelopmentQemuServer::run('ubuntu-root', true, true);
    SeedDevelopmentQemuServer::run('node-worker-a');
    SeedDevelopmentQemuServer::run('node-worker-b', false);

    SeedDevelopmentQemuServer::run('ubuntu-root', true, true);

    expect(Server::query()->findOrFail(0)->ip)->toBe('192.168.122.10')
        ->and(Node::query()->where('uuid', 'like', 'development-qemu-node-worker-%')->count())->toBe(2);
});

it('points instance node workers at the instance coolify port', function () {
    useDevelopmentQemuInstance('main', 3, sys_get_temp_dir().'/coolify-qemu-node-port-'.uniqid());
    config(['development-qemu.coolify_host_port' => 20030]);

    $node = SeedDevelopmentQemuServer::run('node-worker-a');

    expect($node->ip)->toBe('10.221.3.50')
        ->and($node->sentinel_url)->toBe('http://10.221.3.1:20030');
});

it('rejects node worker profiles as localhost', function () {
    Process::fake();

    expect(fn () => SeedDevelopmentQemuServer::run('node-worker-a', true, true))->toThrow(InvalidArgumentException::class, 'localhost')
        ->and(fn () => ManageDevelopmentQemuVm::run('node-worker-a', true))->toThrow(InvalidArgumentException::class, 'localhost');
    Process::assertNothingRan();
});

it('bootstraps seeded development workers into a usable cluster', function () {
    $first = SeedDevelopmentQemuServer::run('node-worker-a');
    $second = SeedDevelopmentQemuServer::run('node-worker-b', false);

    InstallSentinel::shouldRun()->twice()->andReturn('');
    ValidateNode::shouldRun()->twice()->andReturnTrue();
    PingFluxConnection::shouldRun()->twice()->andReturn([
        'command_id' => 'command',
        'nonce' => 'nonce',
        'sentinel_time_unix_ms' => 1,
        'sentinel_version' => 'main',
        'boot_id' => 'boot',
        'latency_ms' => 1,
    ]);
    ReconcileNodeClusterNetwork::shouldRun()->once()->andReturn([]);

    expect(Artisan::call('dev:qemu:bootstrap-nodes'))->toBe(Command::SUCCESS);

    $cluster = NodeCluster::query()->where('name', 'Development QEMU mesh')->sole();
    expect($cluster->nodes()->pluck('nodes.id')->all())->toContain($first->id, $second->id);
});

it('replaces the seeded qemu server with the selected non-root equivalent', function () {
    SeedDevelopmentQemuServer::run('ubuntu-root');
    $server = SeedDevelopmentQemuServer::run('ubuntu-non-root');

    expect($server->ip)->toBe('192.168.122.11')
        ->and($server->user)->toBe('coolify')
        ->and(Server::query()->where('uuid', 'like', 'development-qemu-%')->count())->toBe(1);
});

it('reuses an existing vm without touching other managed vms', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-reuse-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    File::ensureDirectoryExists($storagePath);
    File::put("{$storagePath}/coolify-dev-ubuntu-root.qcow2", 'other vm data');
    File::put("{$storagePath}/coolify-dev-ubuntu-non-root.qcow2", 'vm data');

    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(output: 'exists'),
        '* domstate *' => Process::result(output: "shut off\n"),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('ubuntu-non-root');

    Process::assertRan(fn ($process) => $process->command === "virsh start 'coolify-dev-ubuntu-non-root'");
    Process::assertNotRan(fn ($process) => str_starts_with($process->command, 'virt-install '));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh destroy'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh undefine'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'coolify-dev-ubuntu-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'iptables -I LIBVIRT_FWI'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker exec') && str_contains($process->command, 'coolify'));
    expect(File::get("{$storagePath}/coolify-dev-ubuntu-root.qcow2"))->toBe('other vm data')
        ->and(File::get("{$storagePath}/coolify-dev-ubuntu-non-root.qcow2"))->toBe('vm data');
});

it('does not restart an already running vm', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-running-test-'.uniqid()]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(output: 'exists'),
        '* domstate *' => Process::result(output: "running\n"),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('ubuntu-root');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh start'));
    Process::assertNotRan(fn ($process) => str_starts_with($process->command, 'virt-install '));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker exec') && str_contains($process->command, 'fsockopen'));
});

it('freshly recreates only the selected vm', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-fresh-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    File::ensureDirectoryExists($storagePath);
    File::put("{$storagePath}/coolify-dev-ubuntu-root.qcow2", 'other vm data');
    File::put("{$storagePath}/coolify-dev-ubuntu-non-root.qcow2", 'old data');
    File::put("{$storagePath}/coolify-dev-ubuntu-non-root-prepared.qcow2", 'prepared image');

    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(output: 'exists'),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('ubuntu-non-root', true);

    Process::assertRan(fn ($process) => $process->command === "virsh destroy 'coolify-dev-ubuntu-non-root'");
    Process::assertRan(fn ($process) => $process->command === "virsh undefine 'coolify-dev-ubuntu-non-root'");
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'coolify-dev-ubuntu-root'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh start'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-ubuntu-non-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'cloud-init status --wait'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'net-update') && str_contains($process->command, 'ip-dhcp-host') && str_contains($process->command, '192.168.122.11'));
    expect(File::get("{$storagePath}/coolify-dev-ubuntu-root.qcow2"))->toBe('other vm data')
        ->and(File::exists("{$storagePath}/coolify-dev-ubuntu-non-root.qcow2"))->toBeFalse()
        ->and(File::exists("{$storagePath}/coolify-dev-ubuntu-non-root-prepared.qcow2"))->toBeTrue();
});

it('passes the fresh option from the command to the selected vms only', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-fresh-command-test-'.uniqid()]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(output: 'exists'),
        '*' => Process::result(),
    ]);

    expect(Artisan::call('dev:qemu', ['profiles' => ['debian-root'], '--fresh' => true]))->toBe(Command::SUCCESS);

    Process::assertRan(fn ($process) => $process->command === "virsh undefine 'coolify-dev-debian-root'");
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-debian-root'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh destroy') && ! str_contains($process->command, 'coolify-dev-debian-root'));
});

it('rejects qemu vm management outside development', function () {
    config(['app.env' => 'production']);

    expect(fn () => StartDevelopmentQemuVm::run('ubuntu-root'))
        ->toThrow(RuntimeException::class, 'development environments');
});

it('can create a vm without a host database connection', function () {
    config([
        'development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-test-'.uniqid(),
    ]);
    DB::enableQueryLog();
    Process::fake(function ($process) {
        if (str_contains($process->command, 'net-dumpxml')) {
            return Process::result(output: '<network></network>');
        }

        if (str_contains($process->command, 'network inspect')) {
            return Process::result(output: "172.18.0.0/16\n");
        }

        if (str_contains($process->command, 'virsh dominfo')) {
            return Process::result(exitCode: 1);
        }

        if (str_contains($process->command, 'iptables -C')) {
            return Process::result(exitCode: 1);
        }

        return Process::result();
    });

    StartDevelopmentQemuVm::run('ubuntu-root');

    expect(DB::getQueryLog())->toBeEmpty();
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'iptables -I LIBVIRT_FWI'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh domstate') && str_contains($process->command, 'shut off'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'mv ') && str_contains($process->command, 'prepared.qcow2'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'qemu-img create') && str_contains($process->command, 'prepared.qcow2'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'xorriso -as mkisofs -V cidata -graft-points'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'bus=virtio,readonly=on'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mac_in_use'));
    $userData = File::get(config('development-qemu.storage_path').'/coolify-dev-ubuntu-root-user-data.yaml');
    expect($userData)->toContain('docker compose version', 'docker buildx version', 'docker info', 'cloud-init.disabled');
    $cloudInit = Yaml::parse($userData);
    expect($cloudInit['runcmd'][0])->toContain('https://get.docker.com', 'systemctl enable --now docker', 'poweroff');
});

it('reuses a prepared vm image without reinstalling docker', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-prepared-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    File::ensureDirectoryExists($storagePath);
    File::put("{$storagePath}/coolify-dev-ubuntu-root-prepared.qcow2", 'prepared image');
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('ubuntu-root');

    Process::assertNotRan(fn ($process) => str_contains($process->command, 'curl --fail --location'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh domstate'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'qemu-img create') && str_contains($process->command, 'coolify-dev-ubuntu-root-prepared.qcow2'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && ! str_contains($process->command, '--cloud-init'));
    expect(File::exists("{$storagePath}/coolify-dev-ubuntu-root-prepared.qcow2"))->toBeTrue();
});

it('prepares alpine with the compose and buildx packages for a non-root user', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-alpine-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('alpine-non-root');

    $cloudInit = Yaml::parse(File::get("{$storagePath}/coolify-dev-alpine-non-root-user-data.yaml"));
    expect($cloudInit['packages'])->toContain('docker', 'docker-cli-compose', 'docker-cli-buildx')
        ->and($cloudInit['users'][0]['shell'])->toBe('/bin/ash')
        ->and($cloudInit['users'][0]['lock_passwd'])->toBeFalse()
        ->and($cloudInit['runcmd'][0])->toContain('service cgroups start', 'addgroup coolify docker', 'until docker info', 'docker compose version', 'docker buildx version');
});

it('does not cache a vm when preparation does not finish', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-failed-preparation-'.uniqid()]);
    Process::fake(function ($process) {
        if (str_contains($process->command, 'net-dumpxml')) {
            return Process::result(output: '<network></network>');
        }

        if (str_contains($process->command, 'network inspect')) {
            return Process::result(output: "172.18.0.0/16\n");
        }

        if (str_contains($process->command, 'virsh dominfo')) {
            return Process::result(exitCode: 1);
        }

        return str_contains($process->command, 'timeout 900 bash')
            ? Process::result(exitCode: 124)
            : Process::result();
    });

    expect(fn () => StartDevelopmentQemuVm::run('ubuntu-root'))->toThrow(RuntimeException::class);
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'mv ') && str_contains($process->command, 'prepared.qcow2'));
    Process::assertNotRan(fn ($process) => str_starts_with($process->command, 'virt-install ') && ! str_contains($process->command, 'readonly=on'));
});

it('seeds through the coolify container when the host database is unavailable', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-fallback-test-'.uniqid()]);
    SeedDevelopmentQemuServer::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new QueryException('pgsql', 'select 1', [], new Exception('unavailable')));
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(output: 'exists'),
        '*' => Process::result(),
    ]);

    ManageDevelopmentQemuVm::run('ubuntu-root');

    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'coolify' php artisan dev:qemu:seed") && str_contains($process->command, 'ubuntu-root'));
});

it('passes localhost mode to the container when the host database is unavailable', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-localhost-fallback-test-'.uniqid()]);
    SeedDevelopmentQemuServer::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new QueryException('pgsql', 'select 1', [], new Exception('unavailable')));
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '*' => Process::result(),
    ]);

    ManageDevelopmentQemuVm::run('ubuntu-root', true);

    Process::assertRan(fn ($process) => str_contains($process->command, 'dev:qemu:seed') && str_contains($process->command, '--as-localhost'));
});

it('starts and seeds root and non-root profiles together', function () {
    config(['development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-multi-test-'.uniqid()]);
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    ManageDevelopmentQemuVm::run(['ubuntu-root', 'ubuntu-non-root']);

    expect(Server::query()->whereIn('uuid', [
        'development-qemu-ubuntu-root',
        'development-qemu-ubuntu-non-root',
    ])->count())->toBe(2);
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-ubuntu-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-ubuntu-non-root'));
});

it('seeds through the instance coolify container when the host database is unavailable', function () {
    config([
        'development-qemu.storage_path' => sys_get_temp_dir().'/coolify-qemu-instance-fallback-test-'.uniqid(),
        'development-qemu.coolify_container' => 'coolify-fix-deploy-env',
    ]);
    SeedDevelopmentQemuServer::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new QueryException('pgsql', 'select 1', [], new Exception('unavailable')));
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '*' => Process::result(),
    ]);

    ManageDevelopmentQemuVm::run('ubuntu-root');

    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'coolify-fix-deploy-env' php artisan dev:qemu:seed"));
    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'coolify-fix-deploy-env' php -r"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, "docker exec 'coolify' "));
});

it('resolves legacy values when no dev instance is configured', function () {
    $config = developmentQemuConfigFor([]);

    expect($config['instance'])->toBeNull()
        ->and($config['libvirt_network'])->toBe('default')
        ->and($config['bridge'])->toBe('virbr0')
        ->and($config['gateway'])->toBe('192.168.122.1')
        ->and($config['subnet'])->toBe('192.168.122.0/24')
        ->and($config['docker_network'])->toBe('coolify')
        ->and($config['coolify_container'])->toBe('coolify')
        ->and($config['profiles']['ubuntu-root']['domain'])->toBe('coolify-dev-ubuntu-root')
        ->and($config['profiles']['ubuntu-root']['template'])->toBe('coolify-dev-ubuntu-root')
        ->and($config['profiles']['ubuntu-root']['ip'])->toBe('192.168.122.10')
        ->and($config['profiles']['alpine-non-root']['ip'])->toBe('192.168.122.41');
});

it('resolves isolated per-instance values from the environment', function () {
    $legacy = developmentQemuConfigFor([]);
    $config = developmentQemuConfigFor([
        'DEVELOPMENT_QEMU_INSTANCE' => 'fix-deploy-env',
        'DEVELOPMENT_QEMU_SLOT' => '7',
        'DEVELOPMENT_QEMU_DOCKER_NETWORK' => 'coolify-fix-deploy-env',
        'DEVELOPMENT_QEMU_COOLIFY_CONTAINER' => 'coolify-fix-deploy-env',
    ]);
    $profiles = collect($config['profiles']);

    expect($config['instance'])->toBe('fix-deploy-env')
        ->and($config['slot'])->toBe(7)
        ->and($config['libvirt_network'])->toBe('coolify-dev-7')
        ->and($config['bridge'])->toBe('cdevbr7')
        ->and($config['gateway'])->toBe('10.221.7.1')
        ->and($config['subnet'])->toBe('10.221.7.0/24')
        ->and($config['prefix'])->toBe(24)
        ->and($config['docker_network'])->toBe('coolify-fix-deploy-env')
        ->and($config['coolify_container'])->toBe('coolify-fix-deploy-env')
        ->and($config['profiles']['ubuntu-root']['domain'])->toBe('coolify-dev-fix-deploy-env--ubuntu-root')
        ->and($config['profiles']['ubuntu-root']['template'])->toBe('coolify-dev-ubuntu-root')
        ->and($config['profiles']['ubuntu-root']['ip'])->toBe('10.221.7.10')
        ->and($config['profiles']['alpine-non-root']['ip'])->toBe('10.221.7.41')
        ->and($profiles->every(fn (array $profile, string $key) => $profile['domain'] === "coolify-dev-fix-deploy-env--{$key}"))->toBeTrue()
        ->and($config['profiles']['node-worker-a']['domain'])->toBe('coolify-dev-fix-deploy-env--node-worker-a')
        ->and($config['profiles']['node-worker-a']['template'])->toBe('coolify-dev-node-worker-a')
        ->and($config['profiles']['node-worker-a']['ip'])->toBe('10.221.7.50')
        ->and($config['profiles']['node-worker-b']['ip'])->toBe('10.221.7.51')
        ->and($config['profiles']['node-onboarding']['ip'])->toBe('10.221.7.52')
        ->and($profiles->pluck('ip')->unique()->count())->toBe(11)
        ->and($profiles->pluck('mac')->all())->toBe(collect($legacy['profiles'])->pluck('mac')->all())
        ->and($profiles->pluck('uuid')->all())->toBe(collect($legacy['profiles'])->pluck('uuid')->all());
});

it('configures the instance libvirt network and forwarding rule', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-instance-network-test-'.uniqid();
    useDevelopmentQemuInstance('main', 3, $storagePath);
    Process::fake([
        '* net-info *' => Process::result(exitCode: 1),
        '* network inspect *' => Process::result(output: "172.30.0.0/16\n"),
        '*' => Process::result(),
    ]);

    ConfigureDevelopmentQemuHost::run();

    $networkXml = File::get("{$storagePath}/libvirt-network-coolify-dev-3.xml");
    expect($networkXml)->toContain(
        '<name>coolify-dev-3</name>',
        '<bridge name="cdevbr3" stp="on" delay="0"/>',
        '<ip address="10.221.3.1" netmask="255.255.255.0">',
        '<range start="10.221.3.2" end="10.221.3.254"/>',
    )->not->toContain('virbr0', '192.168.122');
    Process::assertRan(fn ($process) => $process->command === "virsh net-info 'coolify-dev-3'");
    Process::assertRan(fn ($process) => $process->command === "virsh net-start 'coolify-dev-3'");
    Process::assertRan(fn ($process) => $process->command === "virsh net-autostart 'coolify-dev-3'");
    Process::assertRan(fn ($process) => str_contains($process->command, "docker network inspect 'coolify-main'"));
    Process::assertRan(fn ($process) => $process->command === "iptables -I LIBVIRT_FWI 1 -s '172.30.0.0/16' -d '10.221.3.0/24' -o 'cdevbr3' -j ACCEPT");
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virbr0'));
    Process::assertRan(fn ($process) => $process->command === 'sysctl -w net.ipv4.conf.cdevbr3.route_localnet=1');
    Process::assertRan(fn ($process) => $process->command === 'iptables -t nat -I PREROUTING 1 -i cdevbr3 -p tcp --dport 8000 -j DNAT --to-destination 127.0.0.1:8000');
});

it('keeps the legacy libvirt network definition unchanged', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-legacy-network-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    Process::fake([
        '* net-info *' => Process::result(exitCode: 1),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '*' => Process::result(),
    ]);

    ConfigureDevelopmentQemuHost::run();

    expect(File::get("{$storagePath}/libvirt-network-default.xml"))->toContain(
        '<name>default</name>',
        '<bridge name="virbr0" stp="on" delay="0"/>',
        '<ip address="192.168.122.1" netmask="255.255.255.0">',
        '<range start="192.168.122.2" end="192.168.122.254"/>',
    );
    Process::assertRan(fn ($process) => $process->command === "iptables -I LIBVIRT_FWI 1 -s '172.18.0.0/16' -d '192.168.122.0/24' -o 'virbr0' -j ACCEPT");
});

it('creates instance vms with instance domains, ips, and shared prepared images', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-instance-vm-test-'.uniqid();
    useDevelopmentQemuInstance('main', 3, $storagePath);
    File::ensureDirectoryExists($storagePath);
    File::put("{$storagePath}/coolify-dev-ubuntu-root-prepared.qcow2", 'prepared image');
    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.30.0.0/16\n"),
        '* dominfo *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('ubuntu-root');

    Process::assertRan(fn ($process) => $process->command === "virsh dominfo 'coolify-dev-main--ubuntu-root'");
    Process::assertRan(fn ($process) => str_starts_with($process->command, "virsh net-update 'coolify-dev-3' add-last ip-dhcp-host") && str_contains($process->command, '52:54:00:ca:00:01') && str_contains($process->command, '10.221.3.10'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'qemu-img create')
        && str_contains($process->command, "'{$storagePath}/coolify-dev-ubuntu-root-prepared.qcow2'")
        && str_contains($process->command, "'{$storagePath}/coolify-dev-main--ubuntu-root.qcow2'"));
    Process::assertRan(fn ($process) => str_starts_with($process->command, 'virt-install ')
        && str_contains($process->command, "--name 'coolify-dev-main--ubuntu-root'")
        && str_contains($process->command, "--network network='coolify-dev-3',model=virtio,mac='52:54:00:ca:00:01'")
        && str_ends_with($process->command, '--check mac_in_use=off'));
    Process::assertRan(fn ($process) => str_contains($process->command, "docker exec 'coolify-main' php -r") && str_contains($process->command, "'10.221.3.10'"));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'curl --fail --location'));
});

it('rejects invalid instance names and slots', function (string $instance, int $slot) {
    useDevelopmentQemuInstance($instance, $slot, sys_get_temp_dir().'/coolify-qemu-invalid-instance-'.uniqid());
    Process::fake();

    expect(fn () => ConfigureDevelopmentQemuHost::run())->toThrow(RuntimeException::class, 'DEVELOPMENT_QEMU_');
    Process::assertNothingRan();
})->with([
    'repeated dashes' => ['fix--deploy', 1],
    'trailing dash' => ['main-', 1],
    'uppercase' => ['Main', 1],
    'slot zero' => ['main', 0],
    'slot too large' => ['main', 255],
]);

/**
 * Evaluate config/development-qemu.php with the given environment, restoring the environment afterwards.
 *
 * @param  array<string, string>  $environment
 * @return array<string, mixed>
 */
function developmentQemuConfigFor(array $environment): array
{
    $keys = ['DEVELOPMENT_QEMU_INSTANCE', 'DEVELOPMENT_QEMU_SLOT', 'DEVELOPMENT_QEMU_DOCKER_NETWORK', 'DEVELOPMENT_QEMU_COOLIFY_CONTAINER', 'DEVELOPMENT_QEMU_COOLIFY_PORT'];
    $original = collect($keys)->mapWithKeys(fn (string $key) => [$key => getenv($key)])->all();

    foreach ($keys as $key) {
        isset($environment[$key]) ? putenv("{$key}={$environment[$key]}") : putenv($key);
    }

    try {
        return require config_path('development-qemu.php');
    } finally {
        foreach ($original as $key => $value) {
            $value === false ? putenv($key) : putenv("{$key}={$value}");
        }
    }
}

function useDevelopmentQemuInstance(string $instance, int $slot, string $storagePath): void
{
    config(['development-qemu' => [
        ...developmentQemuConfigFor([
            'DEVELOPMENT_QEMU_INSTANCE' => $instance,
            'DEVELOPMENT_QEMU_SLOT' => (string) $slot,
            'DEVELOPMENT_QEMU_DOCKER_NETWORK' => "coolify-{$instance}",
            'DEVELOPMENT_QEMU_COOLIFY_CONTAINER' => "coolify-{$instance}",
        ]),
        'storage_path' => $storagePath,
    ]]);
}

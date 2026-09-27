<?php

use App\Actions\Development\ConfigureDevelopmentQemuHost;
use App\Actions\Development\ManageDevelopmentQemuVm;
use App\Actions\Development\SeedDevelopmentQemuServer;
use App\Actions\Development\StartDevelopmentQemuVm;
use App\Console\Commands\ManageDevelopmentQemuVmCommand;
use App\Console\Commands\SeedDevelopmentQemuServerCommand;
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
        ->and(Artisan::all()['dev:qemu'])->toBeInstanceOf(ManageDevelopmentQemuVmCommand::class)
        ->and(Artisan::all()['dev:qemu']->getDefinition()->getArgument('profiles')->isArray())->toBeTrue()
        ->and(Artisan::all()['dev:qemu:seed'])->toBeInstanceOf(SeedDevelopmentQemuServerCommand::class);
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
        'ubuntu-root',
        'ubuntu-non-root',
        'debian-root',
        'debian-non-root',
        'centos-root',
        'centos-non-root',
        'alpine-root',
        'alpine-non-root',
    ])->and($profiles->pluck('ip')->unique()->count())->toBe(8)
        ->and($profiles->pluck('mac')->unique()->count())->toBe(8)
        ->and($profiles->filter(fn (array $profile) => $profile['user'] === 'root')->count())->toBe(4)
        ->and($profiles->filter(fn (array $profile) => $profile['user'] !== 'root')->count())->toBe(4);
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
        ->and($profiles->pluck('ip')->unique()->count())->toBe(8)
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
    $keys = ['DEVELOPMENT_QEMU_INSTANCE', 'DEVELOPMENT_QEMU_SLOT', 'DEVELOPMENT_QEMU_DOCKER_NETWORK', 'DEVELOPMENT_QEMU_COOLIFY_CONTAINER'];
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

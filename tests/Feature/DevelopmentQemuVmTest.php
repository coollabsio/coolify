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

it('deletes managed vm data and freshly creates only the selected vm', function () {
    $storagePath = sys_get_temp_dir().'/coolify-qemu-reset-test-'.uniqid();
    config(['development-qemu.storage_path' => $storagePath]);
    File::ensureDirectoryExists($storagePath);
    File::put("{$storagePath}/coolify-dev-ubuntu-root.qcow2", 'old data');
    File::put("{$storagePath}/coolify-dev-ubuntu-non-root.qcow2", 'old data');

    Process::fake([
        '* net-dumpxml *' => Process::result(output: '<network></network>'),
        '* network inspect *' => Process::result(output: "172.18.0.0/16\n"),
        '* iptables -C *' => Process::result(exitCode: 1),
        '* dominfo *' => Process::result(output: 'exists'),
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('ubuntu-non-root');

    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh destroy') && str_contains($process->command, 'coolify-dev-ubuntu-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh destroy') && str_contains($process->command, 'coolify-dev-ubuntu-non-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh undefine') && str_contains($process->command, 'coolify-dev-ubuntu-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virsh undefine') && str_contains($process->command, 'coolify-dev-ubuntu-non-root'));
    Process::assertNotRan(fn ($process) => str_contains($process->command, 'virsh start'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'virt-install') && str_contains($process->command, 'coolify-dev-ubuntu-non-root'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'net-update') && str_contains($process->command, 'ip-dhcp-host') && str_contains($process->command, '192.168.122.11'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'iptables -D LIBVIRT_FWI'));
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker exec') && str_contains($process->command, 'coolify'));
    expect(File::exists("{$storagePath}/coolify-dev-ubuntu-root.qcow2"))->toBeFalse()
        ->and(File::exists("{$storagePath}/coolify-dev-ubuntu-non-root.qcow2"))->toBeFalse();
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
        '*' => Process::result(),
    ]);

    StartDevelopmentQemuVm::run('alpine-non-root', false);

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

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker exec coolify php artisan dev:qemu:seed') && str_contains($process->command, 'ubuntu-root'));
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

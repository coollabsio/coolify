<?php

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->devRoot = sys_get_temp_dir().'/coolify-dev-script-'.bin2hex(random_bytes(4));
    $main = $this->devRoot.'/main';
    $this->devLog = $this->devRoot.'/commands.log';
    $this->qemuStorage = $this->devRoot.'/qemu';

    mkdir($main.'/scripts', 0777, true);
    mkdir($this->devRoot.'/bin');
    mkdir($this->qemuStorage);
    copy(base_path('scripts/dev'), $main.'/scripts/dev');
    chmod($main.'/scripts/dev', 0755);
    copy(base_path('docker-compose.dev-multi.yml'), $main.'/docker-compose.dev-multi.yml');
    file_put_contents($main.'/.gitignore', ".env\n.dev-instances/\n");

    $git = Process::fromShellCommandline(implode(' && ', [
        'git init -q -b main',
        'git add -A',
        'git -c user.name=test -c user.email=test@example.com commit -q -m init',
        'git worktree add -q -b fix/Some_thing ../wt',
    ]), $main);
    $git->run();
    expect($git->isSuccessful())->toBeTrue($git->getErrorOutput());

    $env = "APP_URL=https://devserver.example.ts.net:8000\nDEV_BIND_ADDRESS=127.0.0.1\n";
    file_put_contents($main.'/.env', $env);
    file_put_contents($this->devRoot.'/wt/.env', $env);

    $fake = <<<'BASH'
#!/usr/bin/env bash
printf '%s %s\n' "$(basename "$0")" "$*" >> "$DEV_TEST_LOG"
case "$(basename "$0") $1" in
  'docker inspect') echo "${DEV_TEST_HEALTH:-running healthy}" ;;
  'docker ps') [[ -n "${DEV_TEST_RUNNING:-}" && "$*" == *"$DEV_TEST_RUNNING"* ]] && echo abc123 ;;
esac
if [[ "$(basename "$0")" == virsh && "$*" == *' list '* ]]; then
  printf '%s\n' coolify-dev-fix-some_thing--ubuntu-root coolify-dev-fix-some_thing-2--ubuntu-root coolify-dev-ubuntu-root
fi
exit 0
BASH;
    foreach (['docker', 'virsh', 'php', 'tailscale', 'iptables'] as $binary) {
        file_put_contents($this->devRoot.'/bin/'.$binary, $fake);
        chmod($this->devRoot.'/bin/'.$binary, 0755);
    }
});

afterEach(function () {
    Process::fromShellCommandline('rm -rf '.escapeshellarg($this->devRoot))->run();
});

function runDevScript(string $directory, array $arguments, array $env = []): Process
{
    $process = new Process(['bash', 'scripts/dev', ...$arguments], test()->devRoot.'/'.$directory, [
        'PATH' => test()->devRoot.'/bin:'.getenv('PATH'),
        'DEV_TEST_LOG' => test()->devLog,
        'DEVELOPMENT_QEMU_STORAGE_PATH' => test()->qemuStorage,
        'COOLIFY_DEV_SERVER_BACKEND' => 'testing-host',
        ...$env,
    ]);
    $process->run();

    return $process;
}

function devScriptLog(): string
{
    return file_exists(test()->devLog) ? file_get_contents(test()->devLog) : '';
}

function devInstanceEnv(string $name): string
{
    return file_get_contents(test()->devRoot."/main/.dev-instances/{$name}.env");
}

it('shows usage and rejects invalid arguments', function () {
    $help = runDevScript('main', ['--help']);
    $extra = runDevScript('main', ['start', 'ubuntu-root', 'extra']);
    $unknown = runDevScript('main', ['restart']);
    $destroyWithoutName = runDevScript('main', ['destroy']);

    expect($help->isSuccessful())->toBeTrue()
        ->and($help->getOutput())->toContain('start [qemu-profile]', 'stop [qemu-profile]', 'run [qemu-profile]', 'destroy <name>')
        ->and($extra->isSuccessful())->toBeFalse()
        ->and($unknown->isSuccessful())->toBeFalse()
        ->and($destroyWithoutName->isSuccessful())->toBeFalse();
});

it('starts the branch instance of the main checkout on the default ports', function () {
    $process = runDevScript('main', ['start']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(devInstanceEnv('main'))
        ->toContain('COMPOSE_PROJECT_NAME=coolify-dev-main')
        ->toContain('DEVELOPMENT_QEMU_INSTANCE=main')
        ->toContain('DEVELOPMENT_QEMU_SLOT=1')
        ->toContain('APP_URL=https://devserver.example.ts.net:8000')
        ->toContain('APP_PORT=8000')
        ->toContain('FORWARD_DB_PORT=5432')
        ->and(devScriptLog())
        ->toContain('docker stop coolify coolify-db coolify-redis')
        ->toContain('docker compose -p coolify-dev-main -f docker-compose.dev-multi.yml --env-file .env --env-file')
        ->toContain('--profile vite --profile mailpit --profile minio --profile testing-host up -d --build')
        ->toContain('coolify-dev-main')
        ->toContain('exec -T coolify php artisan db:seed --class=ServerSeeder --force')
        ->not->toContain('dev:qemu');
});

it('gives a worktree branch its own port block, tailscale ports, and stable data', function () {
    runDevScript('main', ['start']);
    file_put_contents($this->devLog, '');

    $first = runDevScript('wt', ['start']);
    $appKey = preg_replace('/.*^APP_KEY=(\S+)$.*/ms', '$1', devInstanceEnv('fix-some_thing'));
    $second = runDevScript('wt', ['start']);

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput())
        ->and($second->isSuccessful())->toBeTrue($second->getErrorOutput())
        ->and(devInstanceEnv('fix-some_thing'))
        ->toContain('COMPOSE_PROJECT_NAME=coolify-dev-fix-some_thing')
        ->toContain('DEVELOPMENT_QEMU_SLOT=2')
        ->toContain('APP_URL=https://devserver.example.ts.net:20020')
        ->toContain('FORWARD_PUSHER_PORT=20021')
        ->toContain('FORWARD_TERMINAL_PORT=20022')
        ->toContain('FORWARD_DB_PORT=20023')
        ->toContain('VITE_PORT=20025')
        ->toContain("APP_KEY={$appKey}")
        ->toContain('DEV_CHECKOUT='.realpath($this->devRoot.'/wt'))
        ->and(file_get_contents($this->devRoot.'/main/.dev-instances/slots'))->toBe("main 1\nfix-some_thing 2\n")
        ->and(devScriptLog())
        ->toContain('tailscale serve --bg --https=20020 http://127.0.0.1:20020')
        ->toContain('tailscale serve --bg --https=20021 http://127.0.0.1:20021')
        ->toContain('tailscale serve --bg --https=20022 http://127.0.0.1:20022')
        ->toContain('tailscale serve --bg --https=20025 http://127.0.0.1:20025')
        ->not->toContain('docker stop coolify ')
        ->and($this->devRoot.'/wt/.dev-instances')->not->toBeDirectory();
});

it('stops the other running instance of the same checkout', function () {
    runDevScript('main', ['start'], ['COOLIFY_DEV_INSTANCE' => 'older']);
    file_put_contents($this->devLog, '');

    $process = runDevScript('main', ['start'], ['DEV_TEST_RUNNING' => 'coolify-dev-older']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(devScriptLog())->toContain('docker compose -p coolify-dev-older stop');
});

it('fails when the coolify container exits', function () {
    $process = runDevScript('main', ['start'], ['DEV_TEST_HEALTH' => 'exited unhealthy']);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput())->toContain('did not become healthy');
});

it('removes the containers when a run ends', function () {
    $process = runDevScript('wt', ['run']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(devScriptLog())->toContain('logs -f --tail=100')
        ->toContain(' stop')
        ->toContain('down --remove-orphans');
});

it('lists instances with their urls and checkouts', function () {
    runDevScript('main', ['start']);
    runDevScript('wt', ['start']);

    $process = runDevScript('wt', ['urls']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())
        ->toMatch('/^main\s+https:\/\/devserver\.example\.ts\.net:8000\s+running healthy\s+5432/m')
        ->toMatch('/^fix-some_thing\s+https:\/\/devserver\.example\.ts\.net:20020\s+running healthy\s+20023/m');
});

it('destroys only the named instance data, vms, and slot', function () {
    runDevScript('main', ['start']);
    runDevScript('wt', ['start']);
    foreach (['coolify-dev-fix-some_thing--ubuntu-root.qcow2', 'coolify-dev-fix-some_thing-2--ubuntu-root.qcow2', 'coolify-dev-ubuntu-root-prepared.qcow2'] as $file) {
        touch($this->qemuStorage.'/'.$file);
    }
    file_put_contents($this->devLog, '');

    $process = runDevScript('main', ['destroy', 'fix-some_thing']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(devScriptLog())
        ->toContain('down --remove-orphans --volumes')
        ->toContain('virsh --connect qemu:///system undefine coolify-dev-fix-some_thing--ubuntu-root')
        ->not->toContain('undefine coolify-dev-fix-some_thing-2--ubuntu-root')
        ->not->toContain('undefine coolify-dev-ubuntu-root')
        ->toContain('virsh --connect qemu:///system net-undefine coolify-dev-2')
        ->toContain('tailscale serve --https=20020 off')
        ->and($this->qemuStorage.'/coolify-dev-fix-some_thing--ubuntu-root.qcow2')->not->toBeFile()
        ->and($this->qemuStorage.'/coolify-dev-fix-some_thing-2--ubuntu-root.qcow2')->toBeFile()
        ->and($this->qemuStorage.'/coolify-dev-ubuntu-root-prepared.qcow2')->toBeFile()
        ->and($this->devRoot.'/main/.dev-instances/fix-some_thing.env')->not->toBeFile()
        ->and(file_get_contents($this->devRoot.'/main/.dev-instances/slots'))->toBe("main 1\n");
});

it('starts the instance kvm vm with the instance database and names', function () {
    if (! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! file_exists('/dev/kvm') || filetype('/dev/kvm') !== 'char' || ! is_readable('/dev/kvm') || ! is_writable('/dev/kvm')) {
        $this->markTestSkipped('KVM and root access are required for this launcher branch.');
    }

    file_put_contents($this->devRoot.'/bin/php', <<<'BASH'
#!/usr/bin/env bash
printf 'php %s DB=%s:%s LOG=%s QEMU=%s/%s/%s/%s\n' "$*" "$DB_HOST" "$DB_PORT" "$LOG_CHANNEL" "$DEVELOPMENT_QEMU_INSTANCE" "$DEVELOPMENT_QEMU_SLOT" "$DEVELOPMENT_QEMU_DOCKER_NETWORK" "$DEVELOPMENT_QEMU_COOLIFY_CONTAINER" >> "$DEV_TEST_LOG"
BASH);

    runDevScript('main', ['start'], ['COOLIFY_DEV_SERVER_BACKEND' => 'auto']);
    $start = runDevScript('wt', ['start', 'debian-root'], ['COOLIFY_DEV_SERVER_BACKEND' => 'auto']);
    $stop = runDevScript('wt', ['stop'], ['COOLIFY_DEV_SERVER_BACKEND' => 'auto']);

    expect($start->isSuccessful())->toBeTrue($start->getErrorOutput())
        ->and($stop->isSuccessful())->toBeTrue($stop->getErrorOutput())
        ->and(devScriptLog())
        ->toContain('php artisan dev:qemu debian-root --as-localhost DB=127.0.0.1:20023 LOG=stderr QEMU=fix-some_thing/2/coolify-dev-fix-some_thing/coolify-dev-fix-some_thing')
        ->toContain('virsh --connect qemu:///system shutdown coolify-dev-fix-some_thing--ubuntu-root')
        ->not->toContain('shutdown coolify-dev-fix-some_thing-2--ubuntu-root')
        ->not->toContain('shutdown coolify-dev-ubuntu-root')
        ->not->toContain('--profile testing-host up');
});

it('keeps fresh worktree instances writable and stable during the first composer install', function () {
    $initSetup = file_get_contents(base_path('docker/development/etc/s6-overlay/scripts/init-setup.sh'));

    expect(strpos($initSetup, 'touch storage/logs/laravel.log'))->toBeLessThan(strpos($initSetup, 'chown -R www-data:www-data storage'))
        ->and(file_get_contents(base_path('vite.config.js')))->toContain('"**/vendor/**"');
});

it('tears down the instance of a deleted worktree', function () {
    runDevScript('main', ['start']);
    runDevScript('wt', ['start']);
    file_put_contents($this->devLog, '');

    $process = runDevScript('wt', ['teardown']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and(devScriptLog())->toContain('docker compose -p coolify-dev-fix-some_thing')
        ->toContain('down --remove-orphans --volumes')
        ->not->toContain('-p coolify-dev-main ')
        ->and($this->devRoot.'/main/.dev-instances/fix-some_thing.env')->not->toBeFile()
        ->and($this->devRoot.'/main/.dev-instances/main.env')->toBeFile();
});

it('lets jean delete a worktree that never started an instance', function () {
    $process = runDevScript('wt', ['teardown']);

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and($process->getOutput())->toContain('nothing to destroy')
        ->and(devScriptLog())->not->toContain('docker compose');
});

it('never tears down the main checkout instance', function () {
    runDevScript('main', ['start']);

    $process = runDevScript('main', ['teardown']);

    expect($process->isSuccessful())->toBeFalse()
        ->and($this->devRoot.'/main/.dev-instances/main.env')->toBeFile();
});

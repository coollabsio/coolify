<?php

use App\Actions\Sentinel\IssueFluxCertificate;
use App\Actions\Sentinel\MaterializeFluxCertificate;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Models\FluxCertificate;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->directory = sys_get_temp_dir().'/coolify-flux-'.bin2hex(random_bytes(8));
    config(['constants.coolify.base_config_path' => $this->directory, 'constants.sentinel.host_enabled' => true]);
    Cache::flush();
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('writes the leaf and public CA with exact modes without exposing the CA key', function () {
    $certificate = IssueFluxCertificate::run(['flux.example.test']);

    MaterializeFluxCertificate::run($certificate);

    $path = $this->directory.'/flux/pki';
    expect(file_get_contents($path.'/ca.pem'))->toBe($certificate->certificateAuthority->certificate_pem)
        ->and(file_get_contents($path.'/server.pem'))->toBe($certificate->certificate_pem)
        ->and(file_get_contents($path.'/server-key.pem'))->toBe($certificate->private_key_pem)
        ->and(fileperms($path.'/server-key.pem') & 0777)->toBe(0600)
        ->and(fileperms($path.'/server.pem') & 0777)->toBe(0644)
        ->and(fileperms($path.'/ca.pem') & 0777)->toBe(0644)
        ->and(count(File::allFiles($path, true)))->toBe(3);
});

it('replaces files by rename without truncating readers of the prior files', function () {
    $first = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($first);
    $path = $this->directory.'/flux/pki/server-key.pem';
    $reader = fopen($path, 'r');
    $inode = fstat($reader)['ino'];
    $next = IssueFluxCertificate::run(['flux.example.test']);

    try {
        MaterializeFluxCertificate::run($next);
        clearstatcache();
        expect(stream_get_contents($reader))->toBe($first->private_key_pem)
            ->and(file_get_contents($path))->toBe($next->private_key_pem)
            ->and(fileinode($path))->not->toBe($inode)
            ->and(fileperms($path) & 0777)->toBe(0600);
    } finally {
        fclose($reader);
    }
});

it('rejects a mismatched leaf key before replacing files', function () {
    $first = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($first);
    $next = IssueFluxCertificate::run(['flux.example.test']);
    $next->private_key_pem = $first->private_key_pem;

    expect(fn () => MaterializeFluxCertificate::run($next))->toThrow(RuntimeException::class);
    expect(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($first->certificate_pem);
});

it('does not renew a leaf outside the renewal window', function () {
    $certificate = IssueFluxCertificate::run(['flux.example.test']);
    $certificate->update(['valid_until' => now()->addDays(30)->addSecond()]);
    $this->freezeSecond();
    $calls = 0;

    expect(RenewFluxCertificate::run(function () use (&$calls) {
        $calls++;
    }))->toBeFalse()
        ->and($calls)->toBe(0)
        ->and(FluxCertificate::query()->count())->toBe(1);
});

it('renews at the threshold with the same CA and retains the prior leaf', function (int $days) {
    $this->freezeSecond();
    $previous = IssueFluxCertificate::run(['flux.example.test', '192.0.2.1']);
    $previous->update(['valid_until' => now()->addDays($days)]);
    MaterializeFluxCertificate::run($previous);
    $checked = null;

    $result = RenewFluxCertificate::run(function (FluxCertificate $certificate) use (&$checked) {
        $checked = $certificate;
        expect(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($certificate->certificate_pem);
    });

    expect($result)->toBeTrue()
        ->and($checked->certificate_authority_id)->toBe($previous->certificate_authority_id)
        ->and($checked->identities)->toBe($previous->identities)
        ->and($checked->fingerprint)->not->toBe($previous->fingerprint)
        ->and($checked->fresh()->state)->toBe('active')
        ->and($checked->version)->toBe(2)
        ->and($previous->fresh()->state)->toBe('previous')
        ->and($previous->fresh()->private_key_pem)->toBe($previous->private_key_pem)
        ->and(openssl_x509_verify($checked->certificate_pem, openssl_pkey_get_public($previous->certificateAuthority->certificate_pem)))->toBe(1);
})->with([30, 29, -1]);

it('restores the previous files and database state after restart or health failure', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    MaterializeFluxCertificate::run($previous);
    $calls = [];

    $restart = function (FluxCertificate $certificate) use (&$calls, $previous) {
        $calls[] = $certificate->id;
        if ($certificate->id !== $previous->id) {
            throw new RuntimeException('TLS readiness failed');
        }
    };
    expect(fn () => RenewFluxCertificate::run($restart))->toThrow(RuntimeException::class, 'TLS readiness failed');

    $failed = FluxCertificate::query()->whereKeyNot($previous->id)->sole();
    expect($calls)->toBe([$failed->id, $previous->id])
        ->and($previous->fresh()->state)->toBe('active')
        ->and($failed->state)->toBe('failed')
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem)
        ->and(file_get_contents($this->directory.'/flux/pki/server-key.pem'))->toBe($previous->private_key_pem)
        ->and(fileperms($this->directory.'/flux/pki/server-key.pem') & 0777)->toBe(0600);
});

it('prevents overlapping renewals and releases the lock after success', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $nested = null;

    RenewFluxCertificate::run(function () use (&$nested) {
        $nested = RenewFluxCertificate::run(fn () => throw new RuntimeException('Nested restart'));
    });

    $lock = Cache::lock('flux-certificate-renewal', 600);
    try {
        expect($nested)->toBeFalse()->and(FluxCertificate::query()->count())->toBe(2)
            ->and($lock->get())->toBeTrue();
    } finally {
        $lock->release();
    }
});

it('does not create a CA or leaf when no active leaf exists', function () {
    expect(RenewFluxCertificate::run(fn () => throw new RuntimeException('Unexpected restart')))->toBeFalse();
    expect(FluxCertificate::query()->count())->toBe(0);
});

it('does not rotate the CA as part of ordinary renewal', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $previous->certificateAuthority->update(['state' => 'retired']);

    expect(fn () => RenewFluxCertificate::run(fn () => null))->toThrow(RuntimeException::class);
    expect(FluxCertificate::query()->count())->toBe(1);
});

it('keeps renewal disabled until host Sentinel is enabled', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    config(['constants.sentinel.host_enabled' => false]);

    $this->artisan('flux:renew-certificate')->assertSuccessful();
    expect(FluxCertificate::query()->count())->toBe(1);
});

it('runs renewal from the command and returns failure when renewal fails', function () {
    RenewFluxCertificate::shouldRun()->once()->andThrow(new RuntimeException('Restart failed'));

    $this->artisan('flux:renew-certificate')->assertFailed();
});

it('schedules one daily renewal behind the host Sentinel flag', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'flux:renew-certificate'));
    $event = $events->sole();

    expect($event->expression)->toBe('0 0 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->filtersPass(app()))->toBeTrue();
    config(['constants.sentinel.host_enabled' => false]);
    expect($event->filtersPass(app()))->toBeFalse();
});

it('validates the served leaf after restart for root and non-root SSH users', function (string $user, string $identity, string $verification) {
    config(['constants.ssh.mux_enabled' => false, 'constants.flux.port' => 7443]);
    Storage::fake('ssh-keys');
    $key = PrivateKey::factory()->create();
    Server::factory()->create(['id' => 0, 'private_key_id' => $key->id, 'team_id' => $key->team_id, 'user' => $user]);
    $previous = IssueFluxCertificate::run([$identity]);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = $process->command;
        $output = str_contains($process->command, 'openssl s_client')
            ? file_get_contents($this->directory.'/flux/pki/server.pem') : 'coolify-flux';

        return Process::result(output: $output);
    });

    expect(RenewFluxCertificate::run())->toBeTrue()
        ->and($commands)->toHaveCount(2)
        ->and($commands[0])->toContain(($user === 'root' ? '' : 'sudo ').'docker restart coolify-flux')
        ->and($commands[1])->toContain("-connect '127.0.0.1:7443'", '-verify_return_error', $verification." '".$identity."'");
})->with([
    ['root', 'flux.example.test', '-verify_hostname'],
    ['deploy', '192.0.2.1', '-verify_ip'],
    ['deploy', '2001:db8::1', '-verify_ip'],
]);

it('restores the prior files even when database promotion and status writes fail', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    MaterializeFluxCertificate::run($previous);
    $event = 'eloquent.updating: '.FluxCertificate::class;
    Event::listen($event, function (FluxCertificate $certificate) use ($previous) {
        if ($certificate->id !== $previous->id && in_array($certificate->state, ['active', 'failed'])) {
            throw new RuntimeException('Database unavailable');
        }
    });
    $calls = [];
    $restart = function (FluxCertificate $certificate) use (&$calls) {
        $calls[] = $certificate->id;
    };

    try {
        expect(fn () => RenewFluxCertificate::run($restart))->toThrow(RuntimeException::class, 'Database unavailable');
        expect($calls)->toHaveCount(2)
            ->and($calls[1])->toBe($previous->id)
            ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem)
            ->and(file_get_contents($this->directory.'/flux/pki/server-key.pem'))->toBe($previous->private_key_pem);
    } finally {
        Event::forget($event);
    }
});

it('rolls back when Flux serves the old leaf instead of the replacement', function () {
    config(['constants.ssh.mux_enabled' => false]);
    Storage::fake('ssh-keys');
    $key = PrivateKey::factory()->create();
    Server::factory()->create(['id' => 0, 'private_key_id' => $key->id, 'team_id' => $key->team_id]);
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    Process::fake(fn ($process) => Process::result(output: str_contains($process->command, 'openssl s_client') ? $previous->certificate_pem : 'coolify-flux'));

    expect(fn () => RenewFluxCertificate::run())->toThrow(RuntimeException::class, 'Flux did not serve the expected TLS certificate.');
    expect($previous->fresh()->state)->toBe('active')
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem)
        ->and(FluxCertificate::query()->where('state', 'failed')->count())->toBe(1);
});

it('releases the renewal lock even if the rollback restart also fails', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);

    expect(fn () => RenewFluxCertificate::run(fn () => throw new RuntimeException('Restart failed')))
        ->toThrow(RuntimeException::class, 'Flux certificate renewal failed and rollback failed.');
    $lock = Cache::lock('flux-certificate-renewal', 600);
    try {
        expect($lock->get())->toBeTrue()
            ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem);
    } finally {
        $lock->release();
    }
});

it('keeps the renewal lock through the full SSH retry and rollback time budget', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $nested = null;

    RenewFluxCertificate::run(function () use (&$nested) {
        $this->travel(900)->seconds();
        $nested = RenewFluxCertificate::run(fn () => null);
    });

    expect($nested)->toBeFalse()->and(FluxCertificate::query()->count())->toBe(2);
});

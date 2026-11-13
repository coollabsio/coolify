<?php

use App\Actions\Sentinel\IssueFluxCertificate;
use App\Actions\Sentinel\MaterializeFluxCertificate;
use App\Actions\Sentinel\RenewFluxCertificate;
use App\Actions\Sentinel\RestartFlux;
use App\Models\FluxCertificate;
use App\Models\InstanceSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->directory = sys_get_temp_dir().'/coolify-flux-'.bin2hex(random_bytes(8));
    config(['constants.coolify.base_config_path' => $this->directory]);
    setNodesEnabled(true);
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
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays($days)]);
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
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
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
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $nested = null;

    RenewFluxCertificate::run(function () use (&$nested) {
        $nested = RenewFluxCertificate::run(fn () => throw new RuntimeException('Nested restart'));
    });

    $lock = fopen($this->directory.'/flux/.certificate-renewal.lock', 'c');
    try {
        expect($nested)->toBeFalse()->and(FluxCertificate::query()->count())->toBe(2)
            ->and(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

it('does not create a CA or leaf when no active leaf exists', function () {
    expect(RenewFluxCertificate::run(fn () => throw new RuntimeException('Unexpected restart')))->toBeFalse();
    expect(FluxCertificate::query()->count())->toBe(0);
});

it('does not rotate the CA as part of ordinary renewal', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $previous->certificateAuthority->update(['state' => 'retired']);

    expect(fn () => RenewFluxCertificate::run(fn () => null))->toThrow(RuntimeException::class);
    expect(FluxCertificate::query()->count())->toBe(1);
});

it('keeps renewal disabled until host Sentinel is enabled', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    setNodesEnabled(false);

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
    setNodesEnabled(false);
    expect($event->filtersPass(app()))->toBeFalse();
});

it('restarts Flux only as the s6 service of the Coolify container, without SSH', function () {
    Process::fake();

    // The test host has no s6 service directory for Flux.
    expect(is_dir(RestartFlux::SERVICE_DIRECTORY))->toBeFalse()
        ->and(fn () => RestartFlux::run())->toThrow(RuntimeException::class, 'Flux does not run as a service in this container.');
    Process::assertNothingRan();
});

it('restores the prior files even when database promotion and status writes fail', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
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
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $validated = [];
    // Flux keeps serving the old leaf: the check fails for the replacement and passes for the previous leaf.
    $restartAndValidate = function (FluxCertificate $certificate) use ($previous, &$validated): void {
        $validated[] = $certificate->id;
        if ($certificate->id !== $previous->id) {
            throw new RuntimeException('Flux did not serve the expected TLS certificate.');
        }
    };

    expect(fn () => RenewFluxCertificate::run($restartAndValidate))->toThrow(RuntimeException::class, 'Flux did not serve the expected TLS certificate.');
    expect($validated)->toHaveCount(2)
        ->and($validated[1])->toBe($previous->id)
        ->and($previous->fresh()->state)->toBe('active')
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem)
        ->and(FluxCertificate::query()->where('state', 'failed')->count())->toBe(1);
});

it('releases the renewal lock even if the rollback restart also fails', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);

    expect(fn () => RenewFluxCertificate::run(fn () => throw new RuntimeException('Restart failed')))
        ->toThrow(RuntimeException::class, 'Flux certificate renewal failed and rollback failed.');
    $lock = fopen($this->directory.'/flux/.certificate-renewal.lock', 'c');
    try {
        expect(flock($lock, LOCK_EX | LOCK_NB))->toBeTrue()
            ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

it('keeps the renewal lock until release even after a day of clock advance', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $nested = null;

    RenewFluxCertificate::run(function () use (&$nested) {
        $this->travel(86400)->seconds();
        $process = Process::run([PHP_BINARY, '-r', '$lock = fopen($argv[1], "c"); exit(flock($lock, LOCK_EX | LOCK_NB) ? 1 : 0);', $this->directory.'/flux/.certificate-renewal.lock']);
        expect($process->successful())->toBeTrue();
        $nested = RenewFluxCertificate::run(fn () => null);
    });

    expect($nested)->toBeFalse()->and(FluxCertificate::query()->count())->toBe(2);
});

it('restores retained prior files without new content writes after disk writes fail', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $path = $this->directory.'/flux/pki';
    $inode = fileinode($path.'/server-key.pem');
    $limits = posix_getrlimit();
    $softLimit = $limits['soft filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft filesize'];
    $hardLimit = $limits['hard filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard filesize'];
    $handler = pcntl_signal_get_handler(SIGXFSZ);
    $calls = [];
    $restart = function (FluxCertificate $certificate) use ($previous, &$calls, $hardLimit) {
        $calls[] = $certificate->id;
        if ($certificate->id !== $previous->id) {
            pcntl_signal(SIGXFSZ, SIG_IGN);
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, $hardLimit);
            throw new RuntimeException('TLS health failed after disk writes failed');
        }
    };

    try {
        expect(fn () => RenewFluxCertificate::run($restart))->toThrow(RuntimeException::class, 'TLS health failed after disk writes failed');
    } finally {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $softLimit, $hardLimit);
        pcntl_signal(SIGXFSZ, $handler);
    }
    clearstatcache();
    expect($calls)->toHaveCount(2)
        ->and($calls[1])->toBe($previous->id)
        ->and(fileinode($path.'/server-key.pem'))->toBe($inode)
        ->and(file_get_contents($path.'/server.pem'))->toBe($previous->certificate_pem)
        ->and(file_get_contents($path.'/server-key.pem'))->toBe($previous->private_key_pem)
        ->and(fileperms($path.'/server-key.pem') & 0777)->toBe(0600);
});

it('retains prior file inodes until database promotion and then removes backups', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $path = $this->directory.'/flux/pki';
    $inode = fileinode($path.'/server-key.pem');
    $event = 'eloquent.updating: '.FluxCertificate::class;
    $checkedPromotion = false;
    Event::listen($event, function (FluxCertificate $certificate) use ($previous, $path, &$checkedPromotion) {
        if ($certificate->id !== $previous->id && $certificate->state === 'active') {
            $checkedPromotion = true;
            expect(glob($path.'/.previous-*'))->toHaveCount(3);
        }
    });

    try {
        RenewFluxCertificate::run(function () use ($path, $inode) {
            $backup = glob($path.'/.previous-*-server-key.pem');
            expect($backup)->toHaveCount(1)
                ->and(fileinode($backup[0]))->toBe($inode)
                ->and(fileperms($backup[0]) & 0777)->toBe(0600);
        });
        expect($checkedPromotion)->toBeTrue()->and(glob($path.'/.previous-*'))->toBe([]);
    } finally {
        Event::forget($event);
    }
});

it('removes partial backups and does not restart when current files are missing', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $previous->update(['valid_until' => now()->addDays(10)]);
    $path = $this->directory.'/flux/pki';
    unlink($path.'/server-key.pem');
    $calls = 0;
    $restart = function () use (&$calls) {
        $calls++;
    };

    expect(fn () => RenewFluxCertificate::run($restart))->toThrow(RuntimeException::class, 'current Flux certificate files must exist');
    expect($calls)->toBe(0)
        ->and(glob($path.'/.previous-*'))->toBe([])
        ->and(file_get_contents($path.'/server.pem'))->toBe($previous->certificate_pem);
});

it('removes retained links when rollback starts before any active file changed', function () {
    $certificate = IssueFluxCertificate::run(['flux.example.test']);
    $materializer = MaterializeFluxCertificate::make();
    $materializer->handle($certificate);
    $previousFiles = $materializer->retain();

    $materializer->restore($previousFiles);

    expect(glob($this->directory.'/flux/pki/.previous-*'))->toBe([])
        ->and(file_get_contents($this->directory.'/flux/pki/server-key.pem'))->toBe($certificate->private_key_pem);
});

it('cleans temporary files and preserves the current leaf when staging writes fail', function () {
    $previous = IssueFluxCertificate::run(['flux.example.test']);
    MaterializeFluxCertificate::run($previous);
    $candidate = IssueFluxCertificate::run(['flux.example.test']);
    $limits = posix_getrlimit();
    $softLimit = $limits['soft filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft filesize'];
    $hardLimit = $limits['hard filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard filesize'];
    $handler = pcntl_signal_get_handler(SIGXFSZ);

    try {
        pcntl_signal(SIGXFSZ, SIG_IGN);
        posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, $hardLimit);
        expect(fn () => MaterializeFluxCertificate::run($candidate))->toThrow(ErrorException::class);
    } finally {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $softLimit, $hardLimit);
        pcntl_signal(SIGXFSZ, $handler);
    }

    expect(glob($this->directory.'/flux/pki/.flux-*'))->toBe([])
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($previous->certificate_pem)
        ->and(file_get_contents($this->directory.'/flux/pki/server-key.pem'))->toBe($previous->private_key_pem);
});

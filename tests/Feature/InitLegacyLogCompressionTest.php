<?php

use App\Console\Commands\Init;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->logDirectory = sys_get_temp_dir().'/coolify-legacy-log-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->logDirectory);
    Carbon::setTestNow('2026-11-17 10:20:30');
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->logDirectory);
});

it('compresses the old single laravel.log once and removes it', function () {
    file_put_contents("{$this->logDirectory}/laravel.log", "old line 1\nold line 2\n");

    Init::compressLegacyLogFile($this->logDirectory);
    Init::compressLegacyLogFile($this->logDirectory);

    $archive = "{$this->logDirectory}/laravel-legacy-20261117-102030.log.gz";
    expect(file_exists("{$this->logDirectory}/laravel.log"))->toBeFalse()
        ->and(gzdecode(file_get_contents($archive)))->toBe("old line 1\nold line 2\n")
        ->and(glob("{$this->logDirectory}/*"))->toBe([$archive]);
});

it('removes an empty laravel.log without an archive', function () {
    touch("{$this->logDirectory}/laravel.log");

    Init::compressLegacyLogFile($this->logDirectory);

    expect(glob("{$this->logDirectory}/*"))->toBe([]);
});

it('leaves a symlinked laravel.log and the daily log files alone', function () {
    file_put_contents("{$this->logDirectory}/target.log", 'kept');
    symlink("{$this->logDirectory}/target.log", "{$this->logDirectory}/laravel.log");
    file_put_contents("{$this->logDirectory}/laravel-2026-11-16.log", 'daily');

    Init::compressLegacyLogFile($this->logDirectory);

    expect(is_link("{$this->logDirectory}/laravel.log"))->toBeTrue()
        ->and(file_get_contents("{$this->logDirectory}/laravel-2026-11-16.log"))->toBe('daily');
});

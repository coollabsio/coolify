<?php

use App\Support\AtomicFilesystem;
use Illuminate\Foundation\Console\ConfigCacheCommand;
use Illuminate\Foundation\Console\RouteCacheCommand;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/atomic-filesystem-'.bin2hex(random_bytes(4));
    mkdir($this->directory);
});

afterEach(function () {
    array_map('unlink', glob($this->directory.'/*'));
    rmdir($this->directory);
});

it('writes the config and route caches with the atomic filesystem', function (string $command) {
    $files = (fn () => $this->files)->call(app($command));

    expect($files)->toBeInstanceOf(AtomicFilesystem::class);
})->with([
    'config:cache' => ConfigCacheCommand::class,
    'route:cache' => RouteCacheCommand::class,
]);

it('replaces the file with a new complete file and leaves no temporary file', function () {
    $path = $this->directory.'/config.php';
    file_put_contents($path, '<?php return [];');
    $oldInode = fileinode($path);
    $previousUmask = umask(0022);

    try {
        $bytes = (new AtomicFilesystem)->put($path, "<?php return ['app' => ['name' => 'Coolify']];");
    } finally {
        umask($previousUmask);
    }

    clearstatcache();
    expect($bytes)->toBe(46)
        ->and(require $path)->toBe(['app' => ['name' => 'Coolify']])
        ->and(fileinode($path))->not->toBe($oldInode)
        ->and(fileperms($path) & 0777)->toBe(0644)
        ->and(glob($this->directory.'/*'))->toBe([$path]);
});

it('does not write the file outside its directory', function () {
    $path = $this->directory.'/missing/config.php';

    expect(fn () => (new AtomicFilesystem)->put($path, '<?php return [];'))
        ->toThrow(RuntimeException::class, 'Could not create a temporary file');

    expect(file_exists($path))->toBeFalse();
});

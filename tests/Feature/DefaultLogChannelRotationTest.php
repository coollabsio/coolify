<?php

use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\File;

it('rotates the default log file daily and removes files older than the retention', function () {
    $directory = storage_path('framework/testing/log-rotation-'.uniqid());
    File::ensureDirectoryExists($directory);
    foreach (range(1, 10) as $day) {
        File::put($directory.'/laravel-2020-01-'.sprintf('%02d', $day).'.log', 'old');
    }

    config([
        'logging.default' => 'stack',
        'logging.channels.daily.path' => $directory.'/laravel.log',
        'logging.channels.emergency.path' => '/dev/null',
    ]);

    (new LogManager(app()))->error('coolify-rotation-check');

    $files = collect(File::files($directory))->map->getFilename()->sort()->values();
    File::deleteDirectory($directory);

    expect($files)->toHaveCount(config('logging.channels.daily.days'))
        ->and($files->last())->toBe('laravel-'.now()->format('Y-m-d').'.log')
        ->and($files)->not->toContain('laravel-2020-01-01.log');
});

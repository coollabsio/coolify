<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Reverb keeps one file descriptor per WebSocket connection. With an event loop extension its s6 run
 * script raises the soft open-file limit up to the hard limit (at most 65536) and still starts when it
 * cannot. Without one, ReactPHP uses stream_select(), which fails for every connection once a
 * descriptor reaches FD_SETSIZE (1024), so the limit must stay.
 */
beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/reverb-open-files-'.bin2hex(random_bytes(4));
    File::makeDirectory($this->bin);
    // Answers the event loop extension probe (`php -r`) from FAKE_LOOP_EXTENSION, and stands in for
    // `php artisan reverb:start` by reporting the limit Reverb would get.
    File::put("{$this->bin}/php", "#!/bin/sh\nif [ \"\$1\" = \"-r\" ]; then [ \"\$FAKE_LOOP_EXTENSION\" = yes ]; exit \$?; fi\necho \"soft=\$(ulimit -Sn)\"\n");
    chmod("{$this->bin}/php", 0755);
});

afterEach(function () {
    File::deleteDirectory($this->bin);
});

function reverbSoftOpenFileLimit(string $runScript, string $hardLimit, string $softLimit, string $bin, bool $loopExtension = true): string
{
    $result = Process::env(['PATH' => "{$bin}:/usr/bin:/bin", 'FAKE_LOOP_EXTENSION' => $loopExtension ? 'yes' : 'no'])->run(sprintf(
        // The soft limit is lowered first: a hard limit below the current soft limit is invalid.
        'ulimit -Sn %s && ulimit -Hn %s && exec sh %s',
        $softLimit,
        $hardLimit,
        escapeshellarg(base_path($runScript)),
    ));

    expect($result->successful())->toBeTrue();

    return trim(str($result->output())->afterLast('soft='));
}

it('raises the reverb soft open-file limit up to the hard limit, at most 65536', function (string $runScript, string $hardLimit, string $softLimit, string $expected) {
    expect(reverbSoftOpenFileLimit($runScript, $hardLimit, $softLimit, $this->bin))->toBe($expected);
})->with([
    'production' => 'docker/production/etc/s6-overlay/s6-rc.d/reverb/run',
    'development' => 'docker/development/etc/s6-overlay/s6-rc.d/reverb/run',
])->with([
    'hard limit below the cap' => ['4096', '1024', '4096'],
    'hard limit above the cap' => ['200000', '1024', '65536'],
    'hard limit equal to the soft limit' => ['1024', '1024', '1024'],
    'soft limit already above the cap' => ['200000', '100000', '100000'],
]);

it('keeps the reverb soft open-file limit without an event loop extension', function (string $runScript) {
    expect(reverbSoftOpenFileLimit($runScript, '4096', '1024', $this->bin, loopExtension: false))->toBe('1024');
})->with([
    'production' => 'docker/production/etc/s6-overlay/s6-rc.d/reverb/run',
    'development' => 'docker/development/etc/s6-overlay/s6-rc.d/reverb/run',
]);

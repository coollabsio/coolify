<?php

use App\Support\BackupCompression;

it('normalizes supported backup compression CPU percentages', function (mixed $configuredPercentage, int $expected) {
    expect(BackupCompression::cpuPercentage($configuredPercentage))->toBe($expected);
})->with([
    'null defaults to low' => [null, 25],
    'unsupported value defaults to low' => [30, 25],
    'numeric string is accepted' => ['50', 50],
    'low' => [25, 25],
    'medium' => [50, 50],
    'high' => [75, 75],
    'maximum' => [100, 100],
]);

it('builds the shared pigz command with gzip fallback', function () {
    $command = BackupCompression::compressorCommand(75);

    expect($command)
        ->toContain('command -v pigz')
        ->toContain('pigz -3 -p')
        ->toContain('$(nproc) * 75 + 99')
        ->toContain('gzip -3');
});

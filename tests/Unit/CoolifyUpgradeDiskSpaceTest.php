<?php

use App\Services\CoolifyUpgradeDiskSpace;

it('returns the smallest free space of all paths in GB', function () {
    $output = <<<'DF'
Filesystem     1024-blocks      Used Available Capacity Mounted on
/dev/sda1         41152736  20000000  20971520      50% /
/dev/sdb1        104857600  90000000   4718592      95% /var/lib/docker
DF;

    expect(CoolifyUpgradeDiskSpace::parseDfOutput($output))->toBe(4.5);
});

it('returns null when the df output has no free space values', function (string $output) {
    expect(CoolifyUpgradeDiskSpace::parseDfOutput($output))->toBeNull();
})->with([
    'empty' => '',
    'header only' => 'Filesystem     1024-blocks      Used Available Capacity Mounted on',
    'error' => "df: /data/coolify: No such file or directory\nPermission denied",
]);

it('treats only a known value below the minimum as low', function (?float $availableGb, bool $isLow) {
    expect(CoolifyUpgradeDiskSpace::isLow($availableGb))->toBe($isLow);
})->with([
    'unknown' => [null, false],
    'below minimum' => [4.9, true],
    'at minimum' => [5.0, false],
    'above minimum' => [120.3, false],
]);

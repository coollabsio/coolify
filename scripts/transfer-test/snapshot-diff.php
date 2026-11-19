<?php

// Compare two snapshots of snapshot.sh (the source before the transfer, the target after it).
// Prints the columns that ServerTransferCoverage leaves out on purpose with their reason, then one line per
// missing row, extra row, and changed column that has no reason. Exits with the number of unexplained lines.
//
// Usage: php scripts/transfer-test/snapshot-diff.php <source.json> <target.json>

require __DIR__.'/../../vendor/autoload.php';

use Tests\Support\ServerTransferSnapshot;

[$before, $after] = array_map(fn (string $file) => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR), array_slice($argv, 1, 2));
$expected = [
    ...array_fill_keys(ServerTransferSnapshot::LIVE_RUNTIME_COLUMNS, 'Runtime state that the instances update.'),
    'GithubRunnerConfig.is_enabled' => 'The target disables the runners until the user enables them.',
    'Application.publish_directory' => 'The model fills the default when the value is empty.',
    'Application.dockerfile_location' => 'The model fills the default when the value is empty.',
    'Application.docker_compose_location' => 'The model fills the default when the value is empty.',
];
['unexplained' => $unexplained, 'expected' => $expected] = ServerTransferSnapshot::diff($before, $after, $expected);
foreach ($expected as $line) {
    echo "expected {$line}\n";
}
foreach ($unexplained as $line) {
    echo "DIFF     {$line}\n";
}
echo '=== '.count($unexplained)." unexplained difference(s)\n";
exit(min(count($unexplained), 255));

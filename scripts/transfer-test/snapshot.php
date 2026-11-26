<?php

use Tests\Support\ServerTransferSnapshot;

// Writes the portable snapshot of a server (Tests\Support\ServerTransferSnapshot) to a file. snapshot.sh runs it.
// Input (set before `require`): $snapshotServer (server uuid), $snapshotOut (output file).

$snapshot = ServerTransferSnapshot::take($snapshotServer);
file_put_contents($snapshotOut, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo 'snapshot: '.collect($snapshot)->map(fn (array $rows) => count($rows))->sum()." rows\n";

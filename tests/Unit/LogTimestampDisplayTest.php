<?php

test('deployment log timestamps omit microseconds', function () {
    $helper = file_get_contents(dirname(__DIR__, 2).'/bootstrap/helpers/remoteProcess.php');

    expect($helper)
        ->toContain("->format('Y-M-d H:i:s')")
        ->not->toContain("->format('Y-M-d H:i:s.u')");
});

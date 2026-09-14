<?php

it('publishes the service environment file atomically', function () {
    $source = file_get_contents(__DIR__.'/../../app/Models/Service.php');
    $methodStart = strpos($source, 'public function saveComposeConfigs()');
    $methodEnd = strpos($source, 'public function parse(', $methodStart);
    $method = substr($source, $methodStart, $methodEnd - $methodStart);

    expect($method)
        ->not->toContain("'rm -f .env || true'")
        ->toContain("new_public_id().'.env.tmp'")
        ->toContain('tee {$environmentFilename} > /dev/null && mv {$environmentFilename} .env')
        ->toContain('touch {$environmentFilename} && mv {$environmentFilename} .env');
});

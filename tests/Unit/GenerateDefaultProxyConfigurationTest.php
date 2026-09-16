<?php

it('defaults the Traefik proxy image to the current supported version', function () {
    $source = file_get_contents(__DIR__.'/../../bootstrap/helpers/proxy.php');

    preg_match("/'image' => '(traefik:v[\d.]+)',/", $source, $matches);

    expect($matches[1] ?? null)->toBe('traefik:v3.7');
});

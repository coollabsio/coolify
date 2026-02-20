<?php

it('keeps applicationParser env_file explicit and does not auto inject dot env', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    $applicationParserStart = strpos($parsersFile, 'function applicationParser(Application $resource, int $pull_request_id = 0, ?int $preview_id = null, ?string $commit = null): Collection');
    $serviceParserStart = strpos($parsersFile, 'function serviceParser(Service $resource): Collection');
    $applicationParserContent = substr($parsersFile, $applicationParserStart, $serviceParserStart - $applicationParserStart);

    expect($applicationParserContent)
        ->toContain('Preserve only explicitly defined env_file values.')
        ->toContain("\$existingEnvFiles = data_get(\$service, 'env_file');")
        ->toContain('if (! is_null($existingEnvFiles)) {')
        ->toContain("\$payload['env_file'] = \$envFiles;")
        ->not->toContain("->push('.env')");
});

it('keeps serviceParser env_file explicit and does not auto inject dot env', function () {
    $parsersFile = file_get_contents(__DIR__.'/../../bootstrap/helpers/parsers.php');

    $serviceParserStart = strpos($parsersFile, 'function serviceParser(Service $resource): Collection');
    $serviceParserContent = substr($parsersFile, $serviceParserStart);

    expect($serviceParserContent)
        ->toContain('Preserve only explicitly defined env_file values.')
        ->toContain("\$existingEnvFiles = data_get(\$service, 'env_file');")
        ->toContain('if (! is_null($existingEnvFiles)) {')
        ->toContain("\$payload['env_file'] = \$envFiles;")
        ->not->toContain("->push('.env')");
});

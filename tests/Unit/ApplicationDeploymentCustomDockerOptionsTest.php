<?php

test('custom docker options are not skipped when consistent naming and a custom name are both configured', function () {
    $source = file_get_contents(__DIR__.'/../../app/Jobs/ApplicationDeploymentJob.php');

    $customOptionsBlock = str($source)
        ->after('$custom_compose = convertDockerRunToCompose')
        ->before('$this->docker_compose = Yaml::dump')
        ->toString();

    expect($customOptionsBlock)
        ->not->toContain('custom_internal_name')
        ->toContain("array_merge_recursive(\$docker_compose['services'][\$this->application->uuid], \$custom_compose)");
});

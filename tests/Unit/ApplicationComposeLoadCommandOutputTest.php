<?php

it('ensures loadComposeFile uses quiet clone to avoid polluting compose output', function () {
    $applicationModel = file_get_contents(__DIR__.'/../../app/Models/Application.php');

    expect($applicationModel)
        ->toContain("$quietCloneCommand = preg_replace('/^git clone\\b/', 'git clone --quiet', \$cloneCommand) ?? \$cloneCommand;")
        ->toContain('$quietCloneCommand,')
        ->toContain("cat .$workdir$composeFile");
});

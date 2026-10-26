<?php

it('uses Livewire navigation after deleting or converting page resources', function (string $path, array $redirects) {
    $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);

    foreach ($redirects as $redirect) {
        expect($contents)->toContain($redirect);
    }
})->with([
    'S3 storage' => [
        'app/Livewire/Storage/Show.php',
        ["redirectRoute(\$this, 'storage.index')"],
    ],
    'database backup schedule' => [
        'app/Livewire/Project/Database/BackupEdit.php',
        [
            "redirectRoute(\$this, 'project.service.database.backups'",
            "redirectRoute(\$this, 'project.database.backup.index'",
        ],
    ],
    'GitHub source' => [
        'app/Livewire/Source/Github/Change.php',
        ["redirectRoute(\$this, 'source.all')"],
    ],
    'GitLab source' => [
        'app/Livewire/Source/Gitlab/Change.php',
        ["redirectRoute(\$this, 'source.all')"],
    ],
    'service resources' => [
        'app/Livewire/Project/Service/Index.php',
        [
            "return redirectRoute(\$this, 'project.service.configuration', \$this->parameters);",
            "return redirectRoute(\$this, 'project.service.configuration', \$redirectParams);",
        ],
    ],
]);

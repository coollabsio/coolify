<?php

test('service compose config commands never cd into coolify workdirs', function () {
    $workdir = '/data/coolify/services/cju6fccpokhw7zkjtyxrjowk';
    $commands = [
        "mkdir -p $workdir",
        "rm -f {$workdir}/.env || true",
        "touch {$workdir}/.env",
        "echo 'BASE64' | base64 -d | tee {$workdir}/.env > /dev/null",
        "docker compose --project-directory {$workdir} -f {$workdir}/docker-compose.yml up -d",
    ];

    $joined = implode("\n", $commands);

    expect($joined)
        ->not->toContain('cd /data/coolify')
        ->not->toContain("cd $workdir")
        ->toContain("tee {$workdir}/.env")
        ->toContain("--project-directory {$workdir}");
});

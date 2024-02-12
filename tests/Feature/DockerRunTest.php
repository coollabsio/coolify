<?php

it('ConvertDockerTunCommand', function () {
    $input = '--cap-add=NET_ADMIN --cap-add=NET_RAW --cap-add SYS_ADMIN';
    $output = convert_docker_run_to_compose($input);
    expect($output)->toBe([
        'cap_add' => ['NET_ADMIN', 'NET_RAW', 'SYS_ADMIN'],
    ])->ray();
});

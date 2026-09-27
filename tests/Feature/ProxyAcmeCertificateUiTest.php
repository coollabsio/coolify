<?php

it('gates Traefik ACME certificate deletion behind update authorization', function () {
    $view = file_get_contents(resource_path('views/livewire/server/proxy.blade.php'));
    $component = file_get_contents(app_path('Livewire/Server/Proxy.php'));

    expect($view)
        ->toContain('submitAction="deleteTraefikCertificate')
        ->toContain("@can('update', \$server)")
        ->and($component)
        ->toContain("\$this->authorize('update', \$this->server)");
});

it('uses bounded reads and atomic restricted writes for the ACME file', function () {
    $reader = file_get_contents(app_path('Actions/Proxy/GetTraefikCertificates.php'));
    $writer = file_get_contents(app_path('Actions/Proxy/DeleteTraefikCertificate.php'));

    expect($reader)
        ->toContain('MAX_FILE_SIZE_BYTES')
        ->toContain('head -c')
        ->toContain('base64')
        ->and($writer)
        ->toContain('umask 077')
        ->toContain('chmod 600')
        ->toContain('mv --')
        ->not->toContain('rm -f');
});

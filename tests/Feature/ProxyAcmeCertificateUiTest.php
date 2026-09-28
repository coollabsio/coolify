<?php

use App\Actions\Proxy\DeleteTraefikCertificate;
use App\Actions\Proxy\GetTraefikCertificates;

it('shows Traefik ACME certificates with protected delete controls', function () {
    $view = file_get_contents(resource_path('views/livewire/server/proxy.blade.php'));
    $component = file_get_contents(app_path('Livewire/Server/Proxy.php'));

    expect($view)
        ->toContain('TLS certificates')
        ->toContain('Traefik requests a new certificate after the restart')
        ->toContain('wire:click="loadTraefikCertificates"')
        ->toContain('submitAction="deleteTraefikCertificate')
        ->toContain("@can('update', \$server)")
        ->and($component)
        ->toContain('public function loadTraefikCertificates(): void')
        ->toContain('public function deleteTraefikCertificate(string $certificateId, string $password = \'\'): void')
        ->toContain("\$this->authorize('update', \$this->server)")
        ->toContain(GetTraefikCertificates::class)
        ->toContain(DeleteTraefikCertificate::class);
});

it('uses bounded reads and atomic restricted writes for the ACME file', function () {
    $reader = file_get_contents(app_path('Actions/Proxy/GetTraefikCertificates.php'));
    $writer = file_get_contents(app_path('Actions/Proxy/SaveTraefikAcmeFile.php'));

    expect($reader)
        ->toContain('MAX_FILE_SIZE_BYTES')
        ->toContain('head -c')
        ->toContain('base64')
        ->and($writer)
        ->toContain('umask 077')
        ->toContain('chmod 600')
        ->toContain('mv --');
});

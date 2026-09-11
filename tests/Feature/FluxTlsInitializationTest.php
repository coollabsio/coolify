<?php

use App\Actions\Sentinel\InitializeFluxTls;
use App\Models\FluxCertificate;
use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->directory = sys_get_temp_dir().'/coolify-flux-init-'.bin2hex(random_bytes(6));
    config()->set('constants.coolify.base_config_path', $this->directory);
});

afterEach(function () {
    if (is_dir($this->directory)) {
        exec('rm -rf '.escapeshellarg($this->directory));
    }
});

it('issues and materializes the first Flux TLS certificate', function () {
    $certificate = InitializeFluxTls::run(['flux', '127.0.0.1', '::1']);

    expect($certificate->state)->toBe('active')
        ->and($certificate->identities)->toBe(['flux', '127.0.0.1', '::1'])
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($certificate->certificate_pem)
        ->and(file_get_contents($this->directory.'/flux/pki/ca.pem'))->toBe($certificate->certificateAuthority->certificate_pem)
        ->and(FluxCertificate::query()->where('state', 'active')->count())->toBe(1);
});

it('reuses and rematerializes the active Flux TLS certificate', function () {
    $first = InitializeFluxTls::run(['flux']);
    unlink($this->directory.'/flux/pki/server.pem');

    $second = InitializeFluxTls::run(['ignored.example.com']);

    expect($second->is($first))->toBeTrue()
        ->and(file_get_contents($this->directory.'/flux/pki/server.pem'))->toBe($first->certificate_pem)
        ->and(FluxCertificate::query()->count())->toBe(1);
});

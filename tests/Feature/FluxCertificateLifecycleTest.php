<?php

use App\Actions\Sentinel\EnsureFluxCertificateAuthority;
use App\Actions\Sentinel\IssueFluxCertificate;
use App\Models\FluxCertificate;
use App\Models\FluxCertificateAuthority;
use App\Models\InstanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
});

it('creates and reuses one active installation CA', function () {
    $authority = EnsureFluxCertificateAuthority::run();

    expect($authority)->toBeInstanceOf(FluxCertificateAuthority::class);
    expect(EnsureFluxCertificateAuthority::run()->id)->toBe($authority->id)
        ->and(FluxCertificateAuthority::query()->count())->toBe(1)
        ->and($authority->state)->toBe('active')
        ->and($authority->version)->toBe(1);
});

it('issues a self-signed CA valid for exactly 100 calendar years', function () {
    $authority = EnsureFluxCertificateAuthority::run();
    expect($authority)->toBeInstanceOf(FluxCertificateAuthority::class);
    $parsed = openssl_x509_parse($authority->certificate_pem);
    $start = CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t']);
    $end = CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']);

    expect($end->equalTo($start->addYearsNoOverflow(100)))->toBeTrue()
        ->and($parsed['extensions']['basicConstraints'])->toBe('CA:TRUE, pathlen:0')
        ->and($parsed['extensions']['keyUsage'])->toBe('Certificate Sign, CRL Sign')
        ->and(openssl_x509_verify($authority->certificate_pem, openssl_pkey_get_public($authority->certificate_pem)))->toBe(1)
        ->and($authority->valid_from->timestamp)->toBe($start->timestamp)
        ->and($authority->valid_until->timestamp)->toBe($end->timestamp)
        ->and($authority->fingerprint)->toBe(openssl_x509_fingerprint($authority->certificate_pem, 'sha256'));
});

it('encrypts both private keys at rest and hides them from serialization', function () {
    $certificate = IssueFluxCertificate::run(['flux.example.test']);
    expect($certificate)->toBeInstanceOf(FluxCertificate::class);

    foreach ([$certificate, $certificate->certificateAuthority] as $model) {
        $model->refresh();
        $stored = $model->getRawOriginal('private_key_pem');

        expect($stored)->not->toBe($model->private_key_pem)
            ->not->toContain('PRIVATE KEY')
            ->and(Crypt::decryptString($stored))->toBe($model->private_key_pem)
            ->and(openssl_x509_check_private_key($model->certificate_pem, $model->private_key_pem))->toBeTrue()
            ->and($model->toArray())->not->toHaveKey('private_key_pem');
    }
});

it('issues an exact DNS IPv4 and IPv6 SAN set with server authentication only', function () {
    $identities = ['flux.example.test', '192.0.2.10', '2001:db8::10'];
    $certificate = IssueFluxCertificate::run($identities);
    expect($certificate)->toBeInstanceOf(FluxCertificate::class);
    $parsed = openssl_x509_parse($certificate->certificate_pem);

    expect($parsed['extensions']['subjectAltName'])->toBe('DNS:flux.example.test, IP Address:192.0.2.10, IP Address:2001:DB8:0:0:0:0:0:10')
        ->and($parsed['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and($parsed['extensions']['extendedKeyUsage'])->toBe('TLS Web Server Authentication')
        ->and($certificate->fresh()->identities)->toBe($identities)
        ->and(openssl_x509_verify($certificate->certificate_pem, openssl_pkey_get_public($certificate->certificateAuthority->certificate_pem)))->toBe(1);
});

it('issues 90 day leaves with unique serial numbers keys and queryable metadata', function () {
    $first = IssueFluxCertificate::run(['flux.example.test']);
    $second = IssueFluxCertificate::run(['flux.example.test']);
    expect($first)->toBeInstanceOf(FluxCertificate::class);
    expect($second)->toBeInstanceOf(FluxCertificate::class);
    $parsed = openssl_x509_parse($first->certificate_pem);

    expect($parsed['validTo_time_t'] - $parsed['validFrom_time_t'])->toBe(90 * 86400)
        ->and($first->valid_from->timestamp)->toBe($parsed['validFrom_time_t'])
        ->and($first->valid_until->timestamp)->toBe($parsed['validTo_time_t'])
        ->and(strtolower($first->serial_number))->toBe(strtolower($parsed['serialNumberHex']))
        ->and($first->serial_number)->not->toBe($second->serial_number)
        ->and($first->private_key_pem)->not->toBe($second->private_key_pem)
        ->and($first->fingerprint)->toBe(openssl_x509_fingerprint($first->certificate_pem, 'sha256'))
        ->and($first->state)->toBe('active')
        ->and($first->version)->toBe(1)
        ->and($first->certificateAuthority->certificates()->count())->toBe(2)
        ->and(FluxCertificate::query()->where('fingerprint', $first->fingerprint)->where('state', 'active')->where('version', 1)->where('valid_until', '>', now())->count())->toBe(1);
});

it('rejects invalid identities before creating any certificates', function (array $identities) {
    expect(fn () => IssueFluxCertificate::run($identities))->toThrow(ValidationException::class);
    expect(FluxCertificateAuthority::query()->count())->toBe(0)
        ->and(FluxCertificate::query()->count())->toBe(0);
})->with([
    'empty list' => [[]],
    'empty name' => [['']],
    'non-string' => [[42]],
    'wildcard' => [['*.example.test']],
    'URL' => [['https://flux.example.test']],
    'port' => [['flux.example.test:7443']],
    'IPv6 brackets' => [['[2001:db8::1]']],
    'CIDR' => [['192.0.2.0/24']],
    'invalid IPv4' => [['999.0.2.1']],
    'IPv6 zone' => [['fe80::1%eth0']],
    'newline injection' => [["flux.test\nDNS.2=evil.test"]],
    'comma injection' => [['flux.test,DNS:evil.test']],
    'leading whitespace' => [[' flux.test']],
    'invalid label' => [['-flux.test']],
    'empty label' => [['flux..test']],
    'long label' => [[str_repeat('a', 64).'.test']],
    'long name' => [[str_repeat('a.', 127).'a']],
    'duplicate identity' => [['flux.test', 'flux.test']],
]);

it('refuses to issue a leaf beyond the CA validity period', function () {
    $authority = EnsureFluxCertificateAuthority::run();
    $authority->update(['valid_until' => now()->addDays(89)]);

    expect(fn () => IssueFluxCertificate::run(['flux.example.test']))->toThrow(RuntimeException::class);
    expect($authority->certificates()->count())->toBe(0);
});

it('fails closed when the instance lock row is missing', function () {
    InstanceSettings::query()->whereKey(0)->delete();

    expect(fn () => EnsureFluxCertificateAuthority::run())->toThrow(ModelNotFoundException::class);
    expect(FluxCertificateAuthority::query()->count())->toBe(0);
});

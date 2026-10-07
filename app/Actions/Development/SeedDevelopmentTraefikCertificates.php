<?php

namespace App\Actions\Development;

use App\Actions\Proxy\GetTraefikCertificates;
use App\Actions\Proxy\SaveTraefikAcmeFile;
use App\Enums\ProxyTypes;
use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class SeedDevelopmentTraefikCertificates
{
    use AsAction;

    public const RESOLVER = 'coolify-dev-examples';

    /** @var array<int, array{main: string, sans: array<int, string>, days: int}> */
    private const EXAMPLES = [
        ['main' => 'app.coolify.test', 'sans' => ['www.app.coolify.test'], 'days' => 60],
        ['main' => 'api.coolify.test', 'sans' => [], 'days' => 5],
        ['main' => '*.preview.coolify.test', 'sans' => ['preview.coolify.test'], 'days' => 89],
        ['main' => 'docs.coolify.test', 'sans' => ['help.coolify.test', 'status.coolify.test'], 'days' => 30],
    ];

    public function __construct(private GetTraefikCertificates $getTraefikCertificates) {}

    /**
     * Replace the example resolver in the server's acme.json with self-signed certificates.
     * Entries from other resolvers stay unchanged.
     */
    public function handle(Server $server): int
    {
        if (! isDev()) {
            throw new RuntimeException('Example TLS certificates may only be seeded in development mode.');
        }
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            throw new RuntimeException("Server {$server->name} does not use Traefik.");
        }

        $contents = $this->getTraefikCertificates->contents($server);
        $data = $contents === null ? [] : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $data[self::RESOLVER] = [
            'Account' => null,
            'Certificates' => array_map($this->certificate(...), self::EXAMPLES),
        ];

        SaveTraefikAcmeFile::run($server, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);

        return count(self::EXAMPLES);
    }

    /**
     * @param  array{main: string, sans: array<int, string>, days: int}  $example
     * @return array{domain: array{main: string, sans: array<int, string>}, certificate: string, key: string, Store: string}
     */
    private function certificate(array $example): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $example['main']], $key);
        $certificate = openssl_csr_sign($csr, null, $key, $example['days'], serial: random_int(1, PHP_INT_MAX));
        openssl_x509_export($certificate, $certificatePem);
        openssl_pkey_export($key, $keyPem);

        return [
            'domain' => ['main' => $example['main'], 'sans' => $example['sans']],
            'certificate' => base64_encode($certificatePem),
            'key' => base64_encode($keyPem),
            'Store' => 'default',
        ];
    }
}

<?php

namespace App\Services\LogDrain;

use App\Rules\ValidHostname;

class AxiomLogDrain extends FluentBitLogDrain
{
    /**
     * Axiom edge deployment for US East 1. See https://axiom.co/docs/reference/edge-deployments.
     */
    public const string DEFAULT_DOMAIN = 'us-east-1.aws.edge.axiom.co';

    public function label(): string
    {
        return 'Axiom';
    }

    public function fields(): array
    {
        return [
            'api_key' => ['label' => 'API key', 'rules' => ['string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'], 'required' => true, 'secret' => true],
            'dataset_name' => ['label' => 'Dataset name', 'rules' => ['string', 'regex:/^[a-zA-Z0-9_\-\.]+$/'], 'required' => true],
            'domain' => ['label' => 'Edge domain', 'rules' => ['string', new ValidHostname], 'default' => self::DEFAULT_DOMAIN],
        ];
    }

    protected function output(): string
    {
        return '[OUTPUT]
    Name            http
    Match           *
    Host            ${AXIOM_DOMAIN}
    Port            443
    URI             /v1/ingest/${AXIOM_DATASET_NAME}
    # Authorization Bearer should be an API token
    Header Authorization Bearer ${AXIOM_API_KEY}
    compress gzip
    format json
    json_date_key _time
    json_date_format iso8601
    tls On
';
    }

    protected function env(array $config): array
    {
        return [
            'AXIOM_DOMAIN' => trim((string) ($config['domain'] ?? '')) ?: self::DEFAULT_DOMAIN,
            'AXIOM_DATASET_NAME' => (string) ($config['dataset_name'] ?? ''),
            'AXIOM_API_KEY' => (string) ($config['api_key'] ?? ''),
        ];
    }
}

<?php

namespace App\Services\DeployTriggers\Contract;

use JsonException;

final readonly class ScanResponse
{
    /**
     * @param  list<string>  $referencePaths
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<array<string, mixed>>  $widened
     */
    public function __construct(
        public string $commit,
        public array $referencePaths,
        public array $evidence,
        public array $widened,
    ) {}

    /**
     * @throws AffectedContractException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AffectedContractException('The scan output is not valid JSON.');
        }

        if (! is_array($data) || ($data['schema_version'] ?? null) !== EvaluateRequest::SCHEMA_VERSION || ! Sha::isValid($data['commit'] ?? null)) {
            throw new AffectedContractException('The scan output breaks the contract.');
        }

        $list = fn (string $key, string $filter): array => array_values(array_filter(is_array($data[$key] ?? null) ? $data[$key] : [], $filter));

        return new self($data['commit'], $list('reference_paths', 'is_string'), $list('evidence', 'is_array'), $list('widened', 'is_array'));
    }
}

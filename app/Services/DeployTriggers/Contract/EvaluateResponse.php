<?php

namespace App\Services\DeployTriggers\Contract;

use JsonException;

final readonly class EvaluateResponse
{
    /**
     * @param  array<string, array<string, mixed>>  $diffs
     * @param  array<string, ApplicationDecision>  $applications
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $after,
        public array $diffs,
        public array $applications,
        public array $errors,
    ) {}

    /**
     * @throws AffectedContractException
     */
    public static function fromJson(string $json, EvaluateRequest $request): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AffectedContractException('The evaluation output is not valid JSON.');
        }

        if (! is_array($data) || ($data['schema_version'] ?? null) !== EvaluateRequest::SCHEMA_VERSION) {
            throw new AffectedContractException('The evaluation output has an unsupported schema version.');
        }

        $after = $data['after'] ?? null;
        if (! Sha::isValid($after) || ($request->after !== null && $after !== $request->after)) {
            throw new AffectedContractException('The evaluation output has an invalid commit.');
        }

        $expected = array_flip($request->applicationIds());
        $applications = [];
        foreach (is_array($data['applications'] ?? null) ? $data['applications'] : [] as $item) {
            $decision = self::decision($item);
            if (! isset($expected[$decision->id]) || isset($applications[$decision->id])) {
                throw new AffectedContractException('The evaluation output has an unknown application.');
            }
            $applications[$decision->id] = $decision;
        }

        if (count($applications) !== count($expected)) {
            throw new AffectedContractException('The evaluation output misses an application.');
        }

        $errors = array_values(array_filter(is_array($data['errors'] ?? null) ? $data['errors'] : [], 'is_string'));

        return new self($after, is_array($data['diffs'] ?? null) ? $data['diffs'] : [], $applications, $errors);
    }

    private static function decision(mixed $item): ApplicationDecision
    {
        if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['reason_code'] ?? null)) {
            throw new AffectedContractException('The evaluation output has an invalid application entry.');
        }

        $decision = $item['decision'] ?? null;
        if (! in_array($decision, ['deploy', 'skip'], true)) {
            throw new AffectedContractException('The evaluation output has an invalid decision.');
        }

        if ($decision === 'skip' && ! in_array($item['reason_code'], ApplicationDecision::SKIP_REASON_CODES, true)) {
            throw new AffectedContractException('The evaluation output skips an application without a matching reason.');
        }

        $base = $item['base'] ?? null;

        return new ApplicationDecision(
            id: $item['id'],
            decision: $decision,
            reasonCode: $item['reason_code'],
            reason: is_string($item['reason'] ?? null) ? $item['reason'] : '',
            base: Sha::isValid($base) ? $base : null,
            matches: array_values(array_filter(is_array($item['matches'] ?? null) ? $item['matches'] : [], 'is_array')),
            lockfile: is_array($item['lockfile'] ?? null) ? $item['lockfile'] : null,
            triggerSet: is_array($item['trigger_set'] ?? null) ? $item['trigger_set'] : null,
        );
    }
}

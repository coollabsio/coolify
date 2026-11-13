<?php

namespace App\Services\ProjectConfig;

/**
 * One planned change. Secret changes never carry values.
 */
final readonly class Change
{
    public function __construct(
        public ChangeKind $kind,
        public string $key,
        public string $path = '',
        public bool $destructive = false,
        public bool $secret = false,
        public mixed $from = null,
        public mixed $to = null,
    ) {}

    /**
     * @return array{kind: string, key: string, path: string, destructive: bool, secret: bool, from: mixed, to: mixed}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'key' => $this->key,
            'path' => $this->path,
            'destructive' => $this->destructive,
            'secret' => $this->secret,
            'from' => $this->secret ? null : $this->from,
            'to' => $this->secret ? null : $this->to,
        ];
    }
}

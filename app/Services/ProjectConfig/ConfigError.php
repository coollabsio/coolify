<?php

namespace App\Services\ProjectConfig;

/**
 * One problem in a coolify.json file. The path uses dots, for example `resources.api.env.STRIPE_KEY`.
 */
final readonly class ConfigError
{
    public function __construct(public string $path, public string $message) {}

    /**
     * @return array{path: string, message: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'message' => $this->message];
    }
}

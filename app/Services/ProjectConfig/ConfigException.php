<?php

namespace App\Services\ProjectConfig;

use RuntimeException;

class ConfigException extends RuntimeException
{
    /**
     * @param  list<ConfigError>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(collect($errors)->map(fn (ConfigError $e) => ($e->path === '' ? '' : "{$e->path}: ").$e->message)->implode(' '));
    }

    public static function at(string $path, string $message): self
    {
        return new self([new ConfigError($path, $message)]);
    }

    /**
     * @return list<ConfigError>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

<?php

namespace App\Services\LogDrain;

use App\Rules\ValidFluentBitConfig;
use RuntimeException;

/**
 * The user writes the whole Fluent Bit config.
 */
class CustomLogDrain extends FluentBitLogDrain
{
    public function label(): string
    {
        return 'Custom Fluent Bit';
    }

    public function fields(): array
    {
        return [
            'config' => ['label' => 'Fluent Bit configuration', 'rules' => ['string', new ValidFluentBitConfig], 'required' => true, 'secret' => true],
            'parser_config' => ['label' => 'Parser configuration', 'rules' => ['string', new ValidFluentBitConfig(parsersOnly: true)], 'secret' => true],
        ];
    }

    public function fluentBitFiles(array $config, string $serverName): array
    {
        $fluentBitConfig = (string) ($config['config'] ?? '');
        $parsers = (string) ($config['parser_config'] ?? '');

        $configError = (new ValidFluentBitConfig)->check($fluentBitConfig);
        if ($configError !== null) {
            throw new RuntimeException("Custom log drain config is not allowed: {$configError}");
        }
        $parsersError = (new ValidFluentBitConfig(parsersOnly: true))->check($parsers);
        if ($parsersError !== null) {
            throw new RuntimeException("Custom log drain parser config is not allowed: {$parsersError}");
        }

        return ['config' => $fluentBitConfig, 'parsers' => $parsers, 'env' => []];
    }

    protected function output(): string
    {
        return '';
    }

    protected function env(array $config): array
    {
        return [];
    }
}

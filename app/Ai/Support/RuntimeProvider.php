<?php

namespace App\Ai\Support;

use App\Models\AiProviderCredential;

class RuntimeProvider
{
    public static function register(AiProviderCredential $credential): string
    {
        $name = "team-cred-{$credential->id}";

        config(["ai.providers.{$name}" => [
            'driver' => $credential->provider->driver(),
            'key' => $credential->api_key,
            'url' => $credential->base_url ?: $credential->provider->defaultUrl(),
            'models' => ['text' => ['default' => $credential->model]],
        ]]);

        return $name;
    }
}

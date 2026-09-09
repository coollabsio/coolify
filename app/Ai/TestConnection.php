<?php

namespace App\Ai;

use App\Ai\Support\RuntimeProvider;
use App\Models\AiProviderCredential;
use Throwable;

use function Laravel\Ai\agent;

class TestConnection
{
    public function handle(AiProviderCredential $credential): array
    {
        try {
            $provider = RuntimeProvider::register($credential);

            $response = agent('Reply with the single word: OK')->prompt(
                'ping',
                provider: $provider,
                model: $credential->model,
                timeout: 20,
            );

            return [
                'ok' => trim($response->text) !== '',
                'message' => 'Connection successful.',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}

<?php

namespace App\Ai;

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\Support\RuntimeProvider;
use App\Models\AiProviderCredential;
use App\Models\Team;

class RunAssistant
{
    public function handle(Team $team, string $message): string
    {
        $credential = AiProviderCredential::where('team_id', $team->id)
            ->where('enabled', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if (! $credential) {
            throw new NoAiCredentialException;
        }

        $provider = RuntimeProvider::register($credential);

        $response = (new CoolifyAssistant)->prompt(
            $message,
            provider: $provider,
            model: $credential->model,
        );

        return $response->text;
    }
}

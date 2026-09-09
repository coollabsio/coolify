<?php

namespace App\Ai;

use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Jobs\Ai\RunAssistantTurn;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

class StartAssistantTurn
{
    private const MAX_PER_MINUTE = 30;

    public function handle(AiConversation $conversation, User $user, string $message): void
    {
        Gate::forUser($user)->authorize('view', $conversation);

        $key = "ai-turn:{$conversation->team_id}:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_MINUTE)) {
            throw new AssistantRateLimitedException;
        }

        if (! AiProviderCredential::defaultForTeam($conversation->team_id)) {
            throw new NoAiCredentialException;
        }

        if (! $conversation->claim($user)) {
            throw new AssistantBusyException;
        }

        RateLimiter::hit($key, 60);

        RunAssistantTurn::dispatch($conversation->id, $message, $user->id);
    }
}

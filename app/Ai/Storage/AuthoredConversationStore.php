<?php

namespace App\Ai\Storage;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Storage\DatabaseConversationStore;

/**
 * Stamps per-message authorship that the base store cannot: SDK writes bypass
 * Eloquent, so we override the single deterministic hook (storeUserMessage) and
 * write author_user_id from the Context set immediately before the turn. The
 * author survives queued dispatch because Context dehydrates into jobs.
 */
class AuthoredConversationStore extends DatabaseConversationStore
{
    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        $messageId = parent::storeUserMessage($conversationId, $participantType, $participantId, $prompt);

        $authorId = Context::get('ai.author_user_id');

        if (! is_null($authorId)) {
            DB::connection($this->connection)
                ->table($this->messagesTable())
                ->where('id', $messageId)
                ->update(['author_user_id' => $authorId]);
        }

        return $messageId;
    }
}

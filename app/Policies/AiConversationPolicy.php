<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\User;

class AiConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AiConversation $conversation): bool
    {
        if (! $user->teams->contains('id', $conversation->team_id)) {
            return false;
        }

        return $conversation->visibility === AiConversation::VISIBILITY_TEAM
            || $conversation->created_by_user_id === $user->id;
    }

    public function update(User $user, AiConversation $conversation): bool
    {
        return $user->teams->contains('id', $conversation->team_id)
            && $conversation->created_by_user_id === $user->id;
    }

    public function delete(User $user, AiConversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }
}

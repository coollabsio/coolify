<?php

namespace App\Livewire\Ai;

use App\Models\AiConversation;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Assistant extends Component
{
    public ?int $activeConversationId = null;

    #[Computed]
    public function enabled(): bool
    {
        return isAiAssistantEnabled();
    }

    /**
     * Resume the running session, or start a fresh one when it has been idle for
     * over an hour. The session otherwise survives reloads and in-app navigation.
     */
    public function openThread(): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->activeConversationId) {
            $current = AiConversation::find($this->activeConversationId);
            if ($current && $current->updated_at?->gt(now()->subHour())) {
                return;
            }
            $this->activeConversationId = null;
        }

        $userId = auth()->id();
        $existing = AiConversation::where('team_id', currentTeam()->id)
            ->where(function ($query) use ($userId) {
                $query->where('visibility', AiConversation::VISIBILITY_TEAM)
                    ->orWhere('created_by_user_id', $userId);
            })
            ->where('updated_at', '>=', now()->subHour())
            ->orderByDesc('updated_at')
            ->first();

        $this->activeConversationId = $existing?->id ?? $this->createConversation($userId);
    }

    public function newThread(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->activeConversationId = $this->createConversation(auth()->id());
    }

    private function createConversation(int $userId): int
    {
        return AiConversation::create([
            'team_id' => currentTeam()->id,
            'created_by_user_id' => $userId,
            'visibility' => AiConversation::VISIBILITY_PRIVATE,
        ])->id;
    }

    public function render()
    {
        return view('livewire.ai.assistant');
    }
}

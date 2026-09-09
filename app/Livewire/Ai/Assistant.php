<?php

namespace App\Livewire\Ai;

use App\Models\AiConversation;
use App\Models\InstanceSettings;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Assistant extends Component
{
    public ?int $activeConversationId = null;

    #[Computed]
    public function enabled(): bool
    {
        return (bool) (InstanceSettings::get()->is_ai_assistant_enabled ?? false)
            && (bool) (currentTeam()->is_ai_assistant_enabled ?? false);
    }

    public function openThread(): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($this->activeConversationId) {
            return;
        }

        $userId = auth()->id();
        $existing = AiConversation::where('team_id', currentTeam()->id)
            ->where(function ($query) use ($userId) {
                $query->where('visibility', AiConversation::VISIBILITY_TEAM)
                    ->orWhere('created_by_user_id', $userId);
            })
            ->orderByDesc('updated_at')
            ->first();

        $this->activeConversationId = $existing?->id ?? AiConversation::create([
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

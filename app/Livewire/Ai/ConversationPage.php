<?php

namespace App\Livewire\Ai;

use App\Models\AiConversation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ConversationPage extends Component
{
    use AuthorizesRequests;

    public ?int $activeConversationId = null;

    public function mount(): void
    {
        $this->activeConversationId = $this->threads()[0]['id'] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function threads(): array
    {
        $userId = auth()->id();

        return AiConversation::where('team_id', currentTeam()->id)
            ->where(function ($query) use ($userId) {
                $query->where('visibility', AiConversation::VISIBILITY_TEAM)
                    ->orWhere('created_by_user_id', $userId);
            })
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'visibility', 'created_by_user_id'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title ?: 'New conversation',
                'visibility' => $c->visibility,
                'mine' => $c->created_by_user_id === $userId,
            ])
            ->all();
    }

    public function newThread(): void
    {
        $conversation = AiConversation::create([
            'team_id' => currentTeam()->id,
            'created_by_user_id' => auth()->id(),
            'visibility' => AiConversation::VISIBILITY_PRIVATE,
        ]);

        $this->activeConversationId = $conversation->id;
        unset($this->threads);
    }

    public function open(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('view', $conversation);
        $this->activeConversationId = $id;
    }

    public function share(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        $conversation->update(['visibility' => AiConversation::VISIBILITY_TEAM]);
        unset($this->threads);
    }

    public function deleteThread(int $id): void
    {
        $conversation = AiConversation::find($id);
        if (! $conversation) {
            return;
        }

        try {
            $this->authorize('delete', $conversation);
        } catch (\Throwable $e) {
            $this->dispatch('error', 'You cannot delete this conversation.');

            return;
        }

        if ($conversation->sdk_conversation_id) {
            DB::table('agent_conversation_messages')->where('conversation_id', $conversation->sdk_conversation_id)->delete();
            DB::table('agent_conversations')->where('id', $conversation->sdk_conversation_id)->delete();
        }

        $conversation->delete();

        if ($this->activeConversationId === $id) {
            $this->activeConversationId = $this->threads()[0]['id'] ?? null;
        }
        unset($this->threads);
    }

    public function render()
    {
        return view('livewire.ai.conversation-page');
    }
}

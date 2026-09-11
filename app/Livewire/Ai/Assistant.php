<?php

namespace App\Livewire\Ai;

use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\StartAssistantTurn;
use App\Ai\Support\PageContext;
use App\Models\AiConversation;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Assistant extends Component
{
    public ?int $activeConversationId = null;

    /** First message shown optimistically when a conversation was just started. */
    public ?string $pendingFirstMessage = null;

    #[Computed]
    public function enabled(): bool
    {
        return isAiAssistantEnabled();
    }

    /**
     * The open conversation's title + uuid, so the widget header mirrors the page
     * and can deep-link to the full-page view for management (rename, pin, etc.).
     *
     * @return array{uuid: string, title: string}|null
     */
    #[Computed]
    public function active(): ?array
    {
        if (! $this->activeConversationId) {
            return null;
        }

        $conversation = AiConversation::find($this->activeConversationId);
        if (! $conversation) {
            return null;
        }

        return [
            'uuid' => $conversation->uuid,
            'title' => $conversation->title ?: 'New conversation',
        ];
    }

    /**
     * Resume the most recent session (idle under an hour); otherwise show the
     * empty composer. Never creates a conversation just for opening the widget.
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
            ->whereNull('archived_at')
            ->where('updated_at', '>=', now()->subHour())
            ->orderByDesc('updated_at')
            ->first();

        $this->activeConversationId = $existing?->id;
        $this->pendingFirstMessage = null;
    }

    public function newThread(): void
    {
        // Reset to the empty composer; a conversation is created on first send.
        $this->activeConversationId = null;
        $this->pendingFirstMessage = null;
    }

    /**
     * Create the conversation and fire the first turn only when a message is sent.
     */
    public function startConversation(string $message, ?string $pagePath = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        $message = trim($message);
        if ($message === '') {
            return;
        }

        $conversation = AiConversation::create([
            'team_id' => currentTeam()->id,
            'created_by_user_id' => auth()->id(),
            'visibility' => AiConversation::VISIBILITY_PRIVATE,
        ]);

        try {
            $pageContext = PageContext::resolve($pagePath);
            app(StartAssistantTurn::class)->handle($conversation, auth()->user(), $message, $pageContext);
        } catch (AssistantBusyException|AssistantRateLimitedException|NoAiCredentialException $e) {
            $conversation->delete();
            $this->dispatch('error', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            $conversation->delete();
            handleError($e, $this);

            return;
        }

        $this->pendingFirstMessage = $message;
        $this->activeConversationId = $conversation->id;
    }

    public function render()
    {
        return view('livewire.ai.assistant');
    }
}

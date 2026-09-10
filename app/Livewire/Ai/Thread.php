<?php

namespace App\Livewire\Ai;

use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\StartAssistantTurn;
use App\Ai\Support\AssistantTurn;
use App\Ai\Support\PageContext;
use App\Jobs\Ai\ResumeAssistantTurn;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Thread extends Component
{
    use AuthorizesRequests;

    public int $conversationId;

    public string $composerMessage = '';

    /** The UI path the user is viewing, sent with each message for page-aware context. */
    public ?string $pagePath = null;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
        $this->authorize('view', $this->conversation());
    }

    #[Computed]
    public function conversation(): AiConversation
    {
        return AiConversation::findOrFail($this->conversationId);
    }

    #[Computed]
    public function busy(): bool
    {
        return $this->conversation()->status === AiConversation::STATUS_RESPONDING;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function messages(): array
    {
        $sdkId = $this->conversation()->sdk_conversation_id;
        if (! $sdkId) {
            return [];
        }

        $rows = DB::table('agent_conversation_messages')
            ->where('conversation_id', $sdkId)
            ->orderBy('id')
            ->get(['id', 'role', 'content', 'author_user_id']);

        $authors = User::whereIn('id', $rows->pluck('author_user_id')->filter()->unique())
            ->pluck('name', 'id');

        return $rows
            ->filter(fn ($row) => in_array($row->role, ['user', 'assistant'], true) && filled($row->content))
            ->map(fn ($row) => [
                'id' => $row->id,
                'role' => $row->role,
                'content' => $row->role === 'user' ? PageContext::strip($row->content) : $row->content,
                'author' => $row->author_user_id ? ($authors[$row->author_user_id] ?? null) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The pending approvals from the latest paused assistant row (on reload).
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function pendingApprovals(): array
    {
        $sdkId = $this->conversation()->sdk_conversation_id;
        if (! $sdkId) {
            return [];
        }

        $row = DB::table('agent_conversation_messages')
            ->where('conversation_id', $sdkId)
            ->where('role', 'assistant')
            ->whereNotNull('approval_state')
            ->orderByDesc('id')
            ->first(['tool_calls', 'approval_state']);

        if (! $row) {
            return [];
        }

        $pending = (array) (json_decode($row->approval_state ?? 'null', true)['pending'] ?? []);
        if ($pending === []) {
            return [];
        }

        $calls = collect(json_decode($row->tool_calls ?? '[]', true))->keyBy('id');

        return collect($pending)->map(fn ($reason, $id) => [
            'id' => $id,
            'tool' => $calls[$id]['name'] ?? 'action',
            'arguments' => $calls[$id]['arguments'] ?? [],
            'reason' => $reason,
        ])->values()->all();
    }

    public function partial(): string
    {
        return AssistantTurn::getPartial($this->conversation()->uuid);
    }

    public function send(): void
    {
        $message = trim($this->composerMessage);
        if ($message === '') {
            return;
        }

        try {
            $pageContext = PageContext::resolve($this->pagePath);
            app(StartAssistantTurn::class)->handle($this->conversation(), auth()->user(), $message, $pageContext);
            $this->composerMessage = '';
            unset($this->conversation, $this->busy);
        } catch (AssistantBusyException|AssistantRateLimitedException|NoAiCredentialException $e) {
            $this->dispatch('error', $e->getMessage());
            $this->dispatch('assistant-idle');
        } catch (\Throwable $e) {
            $this->dispatch('assistant-idle');
            handleError($e, $this);
        }
    }

    public function sendPrompt(string $message, ?string $pagePath = null): void
    {
        $this->composerMessage = $message;
        $this->pagePath = $pagePath;
        $this->send();
    }

    public function stop(): void
    {
        AssistantTurn::requestStop($this->conversation()->uuid);
    }

    public function approve(string $callId): void
    {
        $this->resolve($callId, true);
    }

    public function reject(string $callId): void
    {
        $this->resolve($callId, false);
    }

    private function resolve(string $callId, bool $approved): void
    {
        $conversation = $this->conversation();
        $this->authorize('view', $conversation);

        if (! $conversation->sdk_conversation_id) {
            return;
        }

        if (! $conversation->claim(auth()->user())) {
            $this->dispatch('error', 'This conversation is already responding. Please wait.');

            return;
        }

        ResumeAssistantTurn::dispatch($conversation->id, [$callId => $approved], auth()->id());
        unset($this->conversation, $this->busy);
    }

    public function render()
    {
        return view('livewire.ai.thread');
    }
}

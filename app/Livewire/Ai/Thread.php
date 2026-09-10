<?php

namespace App\Livewire\Ai;

use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\StartAssistantTurn;
use App\Ai\Support\AssistantTurn;
use App\Ai\Support\PageContext;
use App\Ai\Support\ToolActivity;
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

    /** Full-page mode: centers the conversation in a readable column (ChatGPT-style). */
    public bool $wide = false;

    public string $composerMessage = '';

    /** The UI path the user is viewing, sent with each message for page-aware context. */
    public ?string $pagePath = null;

    /** First message shown optimistically when a conversation is opened right after being started. */
    public ?string $initialPending = null;

    public function mount(int $conversationId, bool $wide = false, ?string $initialPending = null): void
    {
        $this->conversationId = $conversationId;
        $this->wide = $wide;
        $this->initialPending = $initialPending;
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
            ->get(['id', 'role', 'content', 'author_user_id', 'tool_results']);

        // Keep id 0 (the root user): reject only nulls, never filter() which drops 0.
        $authorIds = $rows->pluck('author_user_id')->reject(fn ($id) => is_null($id))->unique();
        $users = User::whereIn('id', $authorIds)->get(['id', 'name', 'email', 'avatar_path'])->keyBy('id');
        $viewerId = auth()->id();

        return $rows
            ->flatMap(function ($row) use ($users, $viewerId) {
                if (! in_array($row->role, ['user', 'assistant'], true)) {
                    return [];
                }

                if ($row->role === 'user') {
                    if (! filled($row->content)) {
                        return [];
                    }
                    $sender = ! is_null($row->author_user_id) ? ($users[$row->author_user_id] ?? null) : null;
                    $sender = $sender ?: auth()->user();

                    return [[
                        'id' => $row->id,
                        'role' => 'user',
                        'content' => PageContext::strip($row->content),
                        // Name label only for a teammate's message, not your own.
                        'author' => ($sender && $sender->id !== $viewerId) ? $sender->name : null,
                        'avatar' => $this->avatarFor($sender),
                    ]];
                }

                // Assistant with a real reply.
                if (filled($row->content)) {
                    return [[
                        'id' => $row->id,
                        'role' => 'assistant',
                        'content' => $row->content,
                        'author' => null,
                        'avatar' => null,
                    ]];
                }

                // A silently-empty assistant turn that only rejected a tool call
                // (the user cancelled): surface a persistent "cancelled" note so the
                // transcript records it instead of dropping the row entirely.
                if ($this->wasCancelled($row->tool_results)) {
                    return [[
                        'id' => $row->id.'-cancelled',
                        'role' => 'note',
                        'content' => 'You cancelled this action.',
                        'author' => null,
                        'avatar' => null,
                    ]];
                }

                return [];
            })
            ->values()
            ->all();
    }

    private function wasCancelled(?string $toolResults): bool
    {
        return collect(json_decode($toolResults ?? '[]', true) ?: [])
            ->contains(fn ($result) => is_array($result) && ($result['denied'] ?? false) === true);
    }

    /**
     * The current viewer's avatar, used for optimistic (pending) messages.
     *
     * @return array{initial: string, url: string|null}
     */
    #[Computed]
    public function viewer(): array
    {
        return $this->avatarFor(auth()->user());
    }

    /**
     * @return array{initial: string, url: string|null}
     */
    private function avatarFor(?User $user): array
    {
        $name = $user?->name ?: ($user?->email ?: 'You');

        return [
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'url' => $user && $user->avatar_path ? profile_avatar_url($user) : null,
        ];
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

        return collect($pending)->map(function ($reason, $id) use ($calls) {
            $tool = $calls[$id]['name'] ?? 'action';

            return [
                'id' => $id,
                'tool' => $tool,
                'title' => ToolActivity::label($tool),
                'destructive' => (bool) preg_match('/^(delete|remove|run|execute|deploy|restart|stop)/', (string) $tool),
                // The tool's approval reason already names the resolved resource and its
                // real UUID, so we render that authoritative sentence rather than the
                // model's raw arguments (which may be a slug the model used to resolve).
                'reason' => $reason,
            ];
        })->values()->all();
    }

    public function partial(): string
    {
        return AssistantTurn::getPartial($this->conversation()->uuid);
    }

    public function reasoning(): string
    {
        return AssistantTurn::getReasoning($this->conversation()->uuid);
    }

    public function activity(): string
    {
        return AssistantTurn::getActivity($this->conversation()->uuid);
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
            $this->dispatch('assistant-idle');

            return;
        }

        if (! $conversation->claim(auth()->user())) {
            $this->dispatch('error', 'This conversation is already responding. Please wait.');
            $this->dispatch('assistant-idle');

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

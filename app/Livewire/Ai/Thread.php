<?php

namespace App\Livewire\Ai;

use App\Ai\Contracts\HasApprovalForm;
use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantDisabledException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\StartAssistantTurn;
use App\Ai\Support\AssistantTurn;
use App\Ai\Support\PageContext;
use App\Ai\Support\ToolActivity;
use App\Ai\Tools\CreateDatabase;
use App\Ai\Tools\CreateEnvironment;
use App\Ai\Tools\CreateProject;
use App\Ai\Tools\CreateService;
use App\Ai\Tools\DeleteResource;
use App\Ai\Tools\DeleteServer;
use App\Ai\Tools\RunServerCommand;
use App\Ai\Tools\UpsertEnvironmentVariable;
use App\Jobs\Ai\ResumeAssistantTurn;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\ToolNameResolver;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Thread extends Component
{
    use AuthorizesRequests;

    // Locked: the client must not be able to repoint the component at another
    // team's conversation. mount() authorizes the initial value; #[Locked] keeps
    // it immutable for every later request.
    #[Locked]
    public int $conversationId;

    /** Full-page mode: centers the conversation in a readable column (ChatGPT-style). */
    #[Locked]
    public bool $wide = false;

    public string $composerMessage = '';

    /** The UI path the user is viewing, sent with each message for page-aware context. */
    public ?string $pagePath = null;

    /** First message shown optimistically when a conversation is opened right after being started. */
    #[Locked]
    public ?string $initialPending = null;

    /**
     * User-edited approval form values, keyed [callId][fieldKey]. Survives
     * Livewire re-renders so edits persist until the user approves.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $approvalInputs = [];

    /**
     * Approvable AI tools that expose a generative approval form.
     *
     * @var array<int, class-string>
     */
    private const APPROVAL_FORM_TOOL_CLASSES = [
        CreateDatabase::class,
        CreateService::class,
        CreateProject::class,
        CreateEnvironment::class,
        UpsertEnvironmentVariable::class,
        RunServerCommand::class,
        DeleteResource::class,
        DeleteServer::class,
    ];

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
            ->get(['id', 'role', 'content', 'author_user_id', 'tool_calls', 'tool_results', 'approval_state']);

        // Keep id 0 (the root user): reject only nulls, never filter() which drops 0.
        $authorIds = $rows->pluck('author_user_id')->reject(fn ($id) => is_null($id))->unique();
        $users = User::whereIn('id', $authorIds)->get(['id', 'name', 'email', 'avatar_path'])->keyBy('id');
        $viewerId = auth()->id();
        $decisionLog = $this->conversation()->decision_log ?? [];

        return $rows
            ->flatMap(function ($row) use ($users, $viewerId, $decisionLog) {
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

                $out = [];

                // Assistant with a real reply.
                if (filled($row->content)) {
                    $out[] = [
                        'id' => $row->id,
                        'role' => 'assistant',
                        'content' => $row->content,
                        'author' => null,
                        'avatar' => null,
                    ];
                }

                // A row that paused for approval records each resolved tool call
                // as a durable decision note that describes what the user picked.
                foreach ($this->decisionNotes($row, $decisionLog) as $note) {
                    $out[] = $note;
                }

                return $out;
            })
            ->values()
            ->all();
    }

    /**
     * Durable decision notes for an assistant row that paused for approval, in
     * tool-call order. The description comes from the decision the viewer made
     * (recorded on the conversation); a denied tool result with no recorded
     * decision falls back to a plain cancellation note.
     *
     * @param  array<string, array{decision?: string, reason?: string|null}>  $decisionLog
     * @return array<int, array<string, mixed>>
     */
    private function decisionNotes(object $row, array $decisionLog): array
    {
        $notes = [];
        $seen = [];

        foreach (json_decode($row->tool_calls ?? '[]', true) ?: [] as $call) {
            $id = $call['id'] ?? null;
            if ($id === null || ! isset($decisionLog[$id])) {
                continue;
            }
            $decision = ($decisionLog[$id]['decision'] ?? 'approved') === 'cancelled' ? 'cancelled' : 'approved';
            $reason = $decisionLog[$id]['reason'] ?? null;
            $seen[$id] = true;
            $notes[] = $this->decisionNote($row->id, $id, $decision, $reason);
        }

        foreach (json_decode($row->tool_results ?? '[]', true) ?: [] as $result) {
            $id = $result['id'] ?? null;
            if ($id === null || isset($seen[$id]) || ($result['denied'] ?? false) !== true) {
                continue;
            }
            $seen[$id] = true;
            $notes[] = $this->decisionNote($row->id, $id, 'cancelled', null);
        }

        return $notes;
    }

    /**
     * Build a single transcript decision note.
     *
     * @return array<string, mixed>
     */
    private function decisionNote(string $rowId, string $callId, string $decision, ?string $reason): array
    {
        $fallback = $decision === 'cancelled' ? 'You cancelled this action.' : 'You approved this action.';
        $prefix = $decision === 'cancelled' ? 'Cancelled' : 'Approved';

        return [
            'id' => $rowId.'-decision-'.$callId,
            'role' => 'note',
            'variant' => $decision,
            'content' => filled($reason) ? "{$prefix}: {$reason}" : $fallback,
            'author' => null,
            'avatar' => null,
        ];
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
        // Drop cards the viewer already resolved this session (before the resumed
        // turn clears the pending marker in the database).
        $pending = collect($pending)->except(array_keys($this->conversation()->decision_log ?? []))->all();
        if ($pending === []) {
            return [];
        }

        $calls = collect(json_decode($row->tool_calls ?? '[]', true))->keyBy('id');
        $formTools = $this->approvalFormToolMap();

        return collect($pending)->map(function ($reason, $id) use ($calls, $formTools) {
            $tool = $calls[$id]['name'] ?? 'action';
            $arguments = (array) ($calls[$id]['arguments'] ?? []);

            $form = null;
            if (isset($formTools[$tool])) {
                $formSpec = $formTools[$tool]->approvalForm($arguments)->toArray();
                foreach ($formSpec['fields'] as $field) {
                    if (! in_array($field['type'], ['note', 'locked'], true)) {
                        $this->approvalInputs[$id][$field['key']] ??= $field['value'];
                    }
                }
                $form = $formSpec;
            }

            return [
                'id' => $id,
                'tool' => $tool,
                'title' => ToolActivity::label($tool),
                'destructive' => $form['destructive'] ?? (bool) preg_match('/^(delete|remove|run|execute|deploy|restart|stop)/i', (string) $tool),
                // The tool's approval reason already names the resolved resource and its
                // real UUID, so we render that authoritative sentence rather than the
                // model's raw arguments (which may be a slug the model used to resolve).
                'reason' => $reason,
                'form' => $form,
            ];
        })->values()->all();
    }

    /**
     * Map of tool name (as recorded by the SDK) => tool instance, for the
     * approvable tools that expose a generative approval form.
     *
     * @return array<string, HasApprovalForm>
     */
    private function approvalFormToolMap(): array
    {
        $map = [];
        foreach (self::APPROVAL_FORM_TOOL_CLASSES as $class) {
            $tool = app($class);
            $map[ToolNameResolver::resolve($tool)] = $tool;
        }

        return $map;
    }

    /**
     * Authoritative turn state for the WebSocket-down fallback: when Echo is
     * unavailable the client polls this so the composer is never stuck disabled.
     *
     * @return array{busy: bool, partial: string}
     */
    public function pollStatus(): array
    {
        unset($this->conversation, $this->busy);

        return [
            'busy' => $this->busy(),
            'partial' => $this->partial(),
        ];
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
        } catch (AssistantBusyException|AssistantRateLimitedException|NoAiCredentialException|AssistantDisabledException $e) {
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

        // Only a currently-pending call may be resolved. Validate before claiming
        // so a bogus client-supplied id can't hold the claim or write a junk
        // decision-log entry for a call the SDK will just reject.
        // Also captures the tool-authored reason before the resumed turn clears it.
        $pending = collect($this->pendingApprovals())->firstWhere('id', $callId);
        if (! $pending) {
            $this->dispatch('assistant-idle');

            return;
        }
        $reason = $pending['reason'] ?? null;

        if (! $conversation->claim(auth()->user())) {
            $this->dispatch('error', 'This conversation is already responding. Please wait.');
            $this->dispatch('assistant-idle');

            return;
        }

        try {
            ResumeAssistantTurn::dispatch($conversation->id, [$callId => $this->decisionFor($callId, $approved)], auth()->id());
        } catch (\Throwable $e) {
            // No job will run to release the claim if the queue push fails.
            $conversation->release();
            $this->dispatch('error', 'Could not resume the assistant. Please try again.');
            $this->dispatch('assistant-idle');

            return;
        }

        // Record the decision durably: hides the card at once and leaves a
        // permanent transcript note of what the viewer approved or cancelled.
        $log = $conversation->decision_log ?? [];
        $log[$callId] = ['decision' => $approved ? 'approved' : 'cancelled', 'reason' => $reason];
        $conversation->decision_log = $log;
        $conversation->save();

        unset($this->conversation, $this->busy, $this->pendingApprovals, $this->messages);
    }

    /**
     * Build the serializable decision for a pending call: reject, plain approve,
     * or approve-with-edits (edited form values merged over the model's original
     * arguments). The tool re-validates and re-authorizes the edited arguments.
     *
     * @return array{action: string, arguments?: array<string, mixed>}
     */
    private function decisionFor(string $callId, bool $approved): array
    {
        if (! $approved) {
            return ['action' => 'reject'];
        }

        $call = $this->pendingCall($callId);
        $arguments = (array) ($call['arguments'] ?? []);

        // Never trust the client to edit locked fields: keep only the values the
        // tool's approval form marks editable, so the decision (and its audit note)
        // can only differ from the model's request within the allowed fields.
        $edits = array_intersect_key(
            $this->approvalInputs[$callId] ?? [],
            array_flip($this->editableKeysFor($call)),
        );
        if ($edits === []) {
            return ['action' => 'approve'];
        }

        return [
            'action' => 'edit',
            'arguments' => array_merge($arguments, $edits),
        ];
    }

    /**
     * The keys the tool's approval form allows a user to edit for a pending call.
     *
     * @param  array<string, mixed>|null  $call
     * @return array<int, string>
     */
    private function editableKeysFor(?array $call): array
    {
        $tool = $this->approvalFormToolMap()[$call['name'] ?? ''] ?? null;

        return $tool
            ? $tool->approvalForm((array) ($call['arguments'] ?? []))->editableKeys()
            : [];
    }

    /**
     * The full pending tool call (id, name, arguments) from the latest paused
     * assistant row, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    private function pendingCall(string $callId): ?array
    {
        $sdkId = $this->conversation()->sdk_conversation_id;
        if (! $sdkId) {
            return null;
        }

        $row = DB::table('agent_conversation_messages')
            ->where('conversation_id', $sdkId)
            ->where('role', 'assistant')
            ->whereNotNull('approval_state')
            ->orderByDesc('id')
            ->first(['tool_calls']);

        if (! $row) {
            return null;
        }

        return collect(json_decode($row->tool_calls ?? '[]', true))->firstWhere('id', $callId);
    }

    public function render()
    {
        return view('livewire.ai.thread');
    }
}

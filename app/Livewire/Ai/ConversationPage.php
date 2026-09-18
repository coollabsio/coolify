<?php

namespace App\Livewire\Ai;

use App\Ai\Exceptions\AssistantBusyException;
use App\Ai\Exceptions\AssistantDisabledException;
use App\Ai\Exceptions\AssistantRateLimitedException;
use App\Ai\Exceptions\NoAiCredentialException;
use App\Ai\StartAssistantTurn;
use App\Ai\Support\PageContext;
use App\Models\AiConversation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

class ConversationPage extends Component
{
    use AuthorizesRequests;

    public ?int $activeConversationId = null;

    /** The conversation currently being renamed inline in the sidebar, if any. */
    public ?int $editingId = null;

    public string $editingTitle = '';

    /** Whether the collapsible "Archived" section is expanded in the sidebar. */
    public bool $showArchived = false;

    /** First message to show optimistically when a conversation was just started. */
    public ?string $pendingFirstMessage = null;

    public function mount(?string $uuid = null): void
    {
        if ($uuid === null) {
            return; // default page: no conversation selected
        }

        $conversation = $this->visibleConversations()->where('uuid', $uuid)->first();
        if ($conversation && auth()->user()->can('view', $conversation)) {
            $this->activeConversationId = $conversation->id;

            $pending = session()->pull('assistant_pending');
            if (is_array($pending) && ($pending['uuid'] ?? null) === $uuid) {
                $this->pendingFirstMessage = $pending['message'] ?? null;
            }
        }
    }

    /**
     * Start a brand-new conversation from the default page's composer: create it,
     * fire the first turn, and navigate to its session URL (showing the message
     * optimistically). Nothing is persisted if the turn can't start.
     */
    public function startConversation(string $message, ?string $pagePath = null): void
    {
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
        } catch (AssistantBusyException|AssistantRateLimitedException|NoAiCredentialException|AssistantDisabledException $e) {
            $conversation->delete();
            $this->dispatch('error', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            $conversation->delete();
            handleError($e, $this);

            return;
        }

        session()->flash('assistant_pending', ['uuid' => $conversation->uuid, 'message' => $message]);
        $this->redirect(route('ai.assistant.show', ['uuid' => $conversation->uuid]), navigate: $this->spaNavigate());
    }

    /** Whether SPA navigation is enabled for this instance (mirrors wireNavigate()). */
    private function spaNavigate(): bool
    {
        return filled(wireNavigate());
    }

    /**
     * Base query for conversations visible to the current user in this team.
     */
    private function visibleConversations()
    {
        $userId = auth()->id();

        return AiConversation::where('team_id', currentTeam()->id)
            ->where(function ($query) use ($userId) {
                $query->where('visibility', AiConversation::VISIBILITY_TEAM)
                    ->orWhere('created_by_user_id', $userId);
            });
    }

    /**
     * @param  AiConversation  $c
     * @return array<string, mixed>
     */
    private function toRow($c): array
    {
        return [
            'id' => $c->id,
            'uuid' => $c->uuid,
            'title' => $c->title ?: 'New conversation',
            'visibility' => $c->visibility,
            'mine' => $c->created_by_user_id === auth()->id(),
            'pinned' => $c->pinned_at !== null,
            'archived' => $c->archived_at !== null,
            // Compact "last active" like ChatGPT's list (e.g. "2h", "3d", "now").
            'last_active' => $c->updated_at
                ? trim(str_replace(' ago', '', $c->updated_at->diffForHumans(short: true)))
                : null,
        ];
    }

    /**
     * Active (non-archived) conversations, pinned first then most-recent.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function threads(): array
    {
        return $this->visibleConversations()
            ->whereNull('archived_at')
            ->orderByRaw('pinned_at IS NULL') // pinned first
            ->orderByDesc('pinned_at')
            ->orderByDesc('updated_at')
            ->get(['id', 'uuid', 'title', 'visibility', 'created_by_user_id', 'pinned_at', 'archived_at', 'updated_at'])
            ->map(fn ($c) => $this->toRow($c))
            ->all();
    }

    /**
     * Archived conversations, most-recently archived first.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function archivedThreads(): array
    {
        return $this->visibleConversations()
            ->whereNotNull('archived_at')
            ->orderByDesc('archived_at')
            ->get(['id', 'uuid', 'title', 'visibility', 'created_by_user_id', 'pinned_at', 'archived_at', 'updated_at'])
            ->map(fn ($c) => $this->toRow($c))
            ->all();
    }

    /**
     * The currently open conversation's metadata, used by the main-pane header.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function active(): ?array
    {
        if ($this->activeConversationId === null) {
            return null;
        }

        return collect($this->threads)->merge($this->archivedThreads)
            ->firstWhere('id', $this->activeConversationId);
    }

    public function newThread(): void
    {
        // Don't persist an empty conversation; the default page's composer starts
        // one only when the first message is sent.
        $this->redirect(route('ai.assistant'), navigate: $this->spaNavigate());
    }

    public function open(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('view', $conversation);
        $this->redirect(route('ai.assistant.show', ['uuid' => $conversation->uuid]), navigate: $this->spaNavigate());
    }

    public function share(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        $conversation->update(['visibility' => AiConversation::VISIBILITY_TEAM]);
        unset($this->threads, $this->active);
    }

    public function unshare(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        $conversation->update(['visibility' => AiConversation::VISIBILITY_PRIVATE]);
        unset($this->threads, $this->active);
    }

    public function startRename(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        $this->editingId = $id;
        $this->editingTitle = $conversation->title ?? '';
    }

    public function cancelRename(): void
    {
        $this->editingId = null;
        $this->editingTitle = '';
    }

    public function rename(): void
    {
        if ($this->editingId === null) {
            return;
        }

        $conversation = AiConversation::findOrFail($this->editingId);
        $this->authorize('update', $conversation);

        $title = trim($this->editingTitle);
        if ($title !== '') {
            $conversation->update(['title' => Str::limit($title, 120, '')]);
        }

        $this->cancelRename();
        unset($this->threads, $this->active);
    }

    public function togglePin(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        // Pinning is metadata; don't bump updated_at so recency ordering is preserved.
        $conversation->pinned_at = $conversation->pinned_at ? null : now();
        $conversation->timestamps = false;
        $conversation->save();
        unset($this->threads, $this->active);
    }

    public function archive(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        $conversation->timestamps = false;
        $conversation->forceFill(['archived_at' => now(), 'pinned_at' => null])->save();

        if ($this->activeConversationId === $id) {
            $this->redirect(route('ai.assistant'), navigate: $this->spaNavigate());

            return;
        }
        unset($this->threads, $this->archivedThreads, $this->active);
    }

    public function unarchive(int $id): void
    {
        $conversation = AiConversation::findOrFail($id);
        $this->authorize('update', $conversation);
        $conversation->timestamps = false;
        $conversation->forceFill(['archived_at' => null])->save();
        unset($this->threads, $this->archivedThreads, $this->active);
    }

    public function toggleArchived(): void
    {
        $this->showArchived = ! $this->showArchived;
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
            $this->redirect(route('ai.assistant'), navigate: $this->spaNavigate());

            return;
        }
        unset($this->threads, $this->archivedThreads, $this->active);
    }

    public function render()
    {
        return view('livewire.ai.conversation-page');
    }
}

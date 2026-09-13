<?php

namespace App\Jobs\Ai;

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Support\AssistantTurn;
use App\Ai\Support\PageContext;
use App\Ai\Support\RuntimeProvider;
use App\Ai\Support\ToolActivity;
use App\Events\Ai\AssistantActivity;
use App\Events\Ai\AssistantApprovalRequested;
use App\Events\Ai\AssistantReasoningDelta;
use App\Events\Ai\AssistantStreamDelta;
use App\Events\Ai\AssistantTurnCompleted;
use App\Events\Ai\AssistantTurnFailed;
use App\Jobs\Ai\Concerns\ActsAsTeamMember;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use RuntimeException;
use Throwable;

class RunAssistantTurn implements ShouldQueue
{
    use ActsAsTeamMember, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        public int $conversationId,
        public string $message,
        public int $userId,
        public ?string $pageBlock = null,
        public ?string $pageKey = null,
    ) {}

    public function handle(): void
    {
        $conversation = AiConversation::find($this->conversationId);
        if (! $conversation) {
            return;
        }

        $isFirstTurn = ! $conversation->sdk_conversation_id;

        // Drop a stale stop flag from a late "stop" click on a previous turn, so
        // this fresh turn is not cancelled before it starts.
        AssistantTurn::clearStop($conversation->uuid);

        try {
            $credential = AiProviderCredential::defaultForTeam($conversation->team_id);
            if (! $credential) {
                throw new RuntimeException('No enabled AI provider credential for this team.');
            }

            Context::add('ai.author_user_id', $this->userId);
            $this->actAsTeamMember($this->userId, $conversation->team_id);
            $provider = RuntimeProvider::register($credential);

            $agent = new CoolifyAssistant;
            $conversation->sdk_conversation_id
                ? $agent->continue($conversation->sdk_conversation_id, as: $conversation->team)
                : $agent->forParticipant($conversation->team);

            $message = $this->messageWithPageContext($conversation);

            $final = null;
            $stream = $agent->stream($message, provider: $provider, model: $credential->model);
            $stream->then(function (StreamedAgentResponse $response) use (&$final) {
                $final = $response;
            });

            $partial = '';
            $reasoning = '';
            foreach ($stream as $event) {
                if ($event instanceof TextDelta) {
                    $partial .= $event->delta;
                    AssistantTurn::putPartial($conversation->uuid, $partial);
                    broadcast(new AssistantStreamDelta(
                        $conversation->uuid,
                        AssistantTurn::nextSequence($conversation->uuid),
                        $event->delta,
                    ));
                }

                if ($event instanceof ReasoningDelta) {
                    $reasoning .= $event->delta;
                    AssistantTurn::putReasoning($conversation->uuid, $reasoning);
                    broadcast(new AssistantReasoningDelta(
                        $conversation->uuid,
                        AssistantTurn::nextSequence($conversation->uuid),
                        $reasoning,
                    ));
                }

                // Surface tool activity so a long tool-running pause reads as
                // "Reading servers…" instead of a silent spinner.
                if ($event instanceof ToolCall) {
                    $label = ToolActivity::label($event->toolCall->name);
                    AssistantTurn::putActivity($conversation->uuid, $label);
                    broadcast(new AssistantActivity($conversation->uuid, $label));
                }

                if (AssistantTurn::shouldStop($conversation->uuid)) {
                    break;
                }
            }

            if ($stream->conversationId && ! $conversation->sdk_conversation_id) {
                $conversation->forceFill(['sdk_conversation_id' => $stream->conversationId])->save();
            }

            if ($final?->hasPendingApprovals()) {
                broadcast(new AssistantApprovalRequested(
                    $conversation->uuid,
                    $final->pendingApprovals->map(fn ($a) => [
                        'id' => $a->id,
                        'tool' => $a->tool,
                        'arguments' => $a->arguments,
                        'reason' => $a->reason,
                    ])->all(),
                ));
            } else {
                broadcast(new AssistantTurnCompleted($conversation->uuid, $final?->text ?? $partial));
            }

            // Name the conversation from the first exchange (AI title, else a
            // truncated prompt). Runs in its own job so it never delays the reply.
            if ($isFirstTurn && blank($conversation->title)) {
                GenerateConversationTitle::dispatch($conversation->id, $this->message, $this->userId);
            }
        } catch (AuthorizationException $e) {
            // Deny-before-card / policy denial from a tool: report it cleanly
            // rather than surfacing the raw "This action is unauthorized." string.
            broadcast(new AssistantTurnFailed($conversation->uuid, 'You do not have permission to perform that action.'));
        } catch (Throwable $e) {
            broadcast(new AssistantTurnFailed($conversation->uuid, $e->getMessage()));
        } finally {
            AssistantTurn::clear($conversation->uuid);
            $conversation->release();
            $this->clearTeamMemberContext();
        }
    }

    /**
     * On a hard failure (e.g. the worker killing the process on timeout), the
     * finally block above never runs, so the conversation stays stuck in
     * "responding" and every later send throws AssistantBusyException. Clear the
     * turn state, release the claim, and tell the client the turn failed.
     */
    public function failed(?Throwable $exception): void
    {
        $conversation = AiConversation::find($this->conversationId);
        if (! $conversation) {
            return;
        }

        AssistantTurn::clear($conversation->uuid);
        $conversation->release();
        broadcast(new AssistantTurnFailed($conversation->uuid, 'The assistant stopped unexpectedly. Please try again.'));
    }

    /**
     * Embed the page block into the message only when the user has moved to a
     * different page since their previous message. Staying put sends the bare
     * text, since the agent already has that page earlier in the transcript.
     */
    private function messageWithPageContext(AiConversation $conversation): string
    {
        if (blank($this->pageBlock) || blank($this->pageKey)) {
            return $this->message;
        }

        if ($this->pageKey === $this->previousPageKey($conversation)) {
            return $this->message;
        }

        return PageContext::embed($this->pageKey, $this->pageBlock, $this->message);
    }

    private function previousPageKey(AiConversation $conversation): ?string
    {
        if (! $conversation->sdk_conversation_id) {
            return null;
        }

        $content = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation->sdk_conversation_id)
            ->where('role', 'user')
            ->orderByDesc('id')
            ->value('content');

        return PageContext::keyFromMessage($content);
    }
}

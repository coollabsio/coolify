<?php

namespace App\Jobs\Ai;

use App\Ai\Agents\CoolifyAssistant;
use App\Ai\Support\AssistantTurn;
use App\Ai\Support\RuntimeProvider;
use App\Events\Ai\AssistantApprovalRequested;
use App\Events\Ai\AssistantStreamDelta;
use App\Events\Ai\AssistantTurnCompleted;
use App\Events\Ai\AssistantTurnFailed;
use App\Jobs\Ai\Concerns\ActsAsTeamMember;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use RuntimeException;
use Throwable;

class ResumeAssistantTurn implements ShouldQueue
{
    use ActsAsTeamMember, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    /**
     * Serializable per-call decisions, keyed by tool-call id. Each is
     * ['action' => 'approve'|'reject'|'edit', 'arguments' => array] where
     * arguments is present only for edit. Reconstructed into Decision
     * instances in handle() so the payload stays queue-serializable.
     *
     * @param  array<string, array{action: string, arguments?: array<string, mixed>}>  $decisions
     */
    public function __construct(
        public int $conversationId,
        public array $decisions,
        public int $approverUserId,
    ) {}

    public function handle(): void
    {
        $conversation = AiConversation::find($this->conversationId);
        if (! $conversation) {
            return;
        }

        try {
            if (! $conversation->sdk_conversation_id) {
                throw new RuntimeException('This conversation has no paused turn to resume.');
            }

            $credential = AiProviderCredential::defaultForTeam($conversation->team_id);
            if (! $credential) {
                throw new RuntimeException('No enabled AI provider credential for this team.');
            }

            Context::add('ai.author_user_id', $this->approverUserId);
            $this->actAsTeamMember($this->approverUserId, $conversation->team_id);
            $provider = RuntimeProvider::register($credential);

            $agent = (new CoolifyAssistant)->continue($conversation->sdk_conversation_id, as: $conversation->team);

            $decisions = [];
            foreach ($this->decisions as $callId => $decision) {
                $decisions[$callId] = match ($decision['action'] ?? 'approve') {
                    'edit' => Decision::edit($decision['arguments'] ?? []),
                    'reject' => Decision::reject(),
                    default => Decision::approve(),
                };
            }

            $final = null;
            $stream = $agent->stream(Decisions::from($decisions), provider: $provider, model: $credential->model);
            $stream->then(function (StreamedAgentResponse $response) use (&$final) {
                $final = $response;
            });

            $partial = '';
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

                if (AssistantTurn::shouldStop($conversation->uuid)) {
                    break;
                }
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
        } catch (ApprovalMismatchException $e) {
            broadcast(new AssistantTurnFailed($conversation->uuid, 'This approval was already resolved by someone else.'));
        } catch (Throwable $e) {
            broadcast(new AssistantTurnFailed($conversation->uuid, $e->getMessage()));
        } finally {
            AssistantTurn::clear($conversation->uuid);
            $conversation->release();
            $this->clearTeamMemberContext();
        }
    }
}

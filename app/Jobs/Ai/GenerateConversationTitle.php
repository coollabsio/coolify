<?php

namespace App\Jobs\Ai;

use App\Ai\Support\PageContext;
use App\Ai\Support\RuntimeProvider;
use App\Events\Ai\AssistantConversationRenamed;
use App\Jobs\Ai\Concerns\ActsAsTeamMember;
use App\Models\AiConversation;
use App\Models\AiProviderCredential;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Laravel\Ai\AnonymousAgent;
use Throwable;

class GenerateConversationTitle implements ShouldQueue
{
    use ActsAsTeamMember, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(
        public int $conversationId,
        public string $firstMessage,
        public int $userId,
    ) {}

    public function handle(): void
    {
        $conversation = AiConversation::find($this->conversationId);
        if (! $conversation || filled($conversation->title)) {
            return;
        }

        $prompt = trim(PageContext::strip($this->firstMessage));
        $title = $this->fallbackTitle($prompt);

        try {
            $credential = AiProviderCredential::defaultForTeam($conversation->team_id);
            if ($credential && $prompt !== '') {
                $this->actAsTeamMember($this->userId, $conversation->team_id);
                $provider = RuntimeProvider::register($credential);
                $generated = $this->generateTitle($prompt, $provider, $credential->model);
                if ($generated !== '') {
                    $title = $generated;
                }
            }
        } catch (Throwable $e) {
            // Keep the truncated-prompt fallback already in $title.
        } finally {
            $this->clearTeamMemberContext();
        }

        $conversation->forceFill(['title' => $title])->save();

        broadcast(new AssistantConversationRenamed($conversation->team_id, $conversation->id, $title));
    }

    private function generateTitle(string $prompt, string $provider, string $model): string
    {
        $agent = new AnonymousAgent(
            instructions: <<<'PROMPT'
            You write a short, specific title for a conversation, given its opening
            message. Reply with the title only: 3 to 6 words, sentence case, no
            surrounding quotes, no trailing punctuation, and no "Title:" prefix.
            PROMPT,
            messages: [],
            tools: [],
        );

        $response = $agent->prompt($prompt, provider: $provider, model: $model, timeout: 30);

        return $this->cleanTitle($response->text ?? '');
    }

    private function cleanTitle(string $raw): string
    {
        $title = trim($raw);
        $title = preg_replace('/^\s*(title\s*:\s*)/i', '', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B\"'`");
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
        $title = rtrim($title, '.,;:!?-');

        if ($title === '') {
            return '';
        }

        return Str::limit($title, 60, '');
    }

    private function fallbackTitle(string $prompt): string
    {
        $prompt = trim(preg_replace('/\s+/', ' ', $prompt) ?? $prompt);

        if ($prompt === '') {
            return 'New conversation';
        }

        return Str::limit($prompt, 48);
    }
}

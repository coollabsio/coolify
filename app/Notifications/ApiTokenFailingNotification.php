<?php

namespace App\Notifications;

use App\Models\PersonalAccessToken;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ApiTokenFailingNotification extends CustomEmailNotification
{
    protected string $tokenName;

    protected string $manageUrl;

    public function __construct(
        PersonalAccessToken $token,
        protected int $failedRequests,
        protected string $lastRequest,
    ) {
        $this->onQueue('high');
        $this->tokenName = $token->name;
        $this->manageUrl = base_url().'/security/api-tokens';
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('api_token_failing');
    }

    private function summary(): string
    {
        return "API token '{$this->tokenName}' sent {$this->failedRequests} failed requests in one minute. Coolify now answers its requests with 429 (Too Many Requests) until the failures stop.";
    }

    private function action(): string
    {
        return 'Find the client that uses this token and fix its requests, give the token the permissions it needs, or revoke the token.';
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: API token '{$this->tokenName}' sends many failed requests");
        $mail->view('emails.api-token-failing', [
            'summary' => $this->summary(),
            'action' => $this->action(),
            'lastRequest' => $this->lastRequest,
            'manageUrl' => $this->manageUrl,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: '🔑 API token sends many failed requests',
            description: $this->summary()."\n\n**Action Required:** ".$this->action(),
            color: DiscordMessage::warningColor(),
        );

        $message->addField('Last failed request', $this->lastRequest);
        $message->addField('Manage tokens', "[Open API Tokens]({$this->manageUrl})");

        return $message;
    }

    public function toTelegram(): array
    {
        return [
            'message' => "Coolify: {$this->summary()}\n\nLast failed request: {$this->lastRequest}\n\nAction Required: {$this->action()}",
            'buttons' => [
                [
                    'text' => 'Manage API tokens',
                    'url' => $this->manageUrl,
                ],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $message = e($this->summary()).'<br/><br/>';
        $message .= 'Last failed request: '.e($this->lastRequest).'<br/><br/>';
        $message .= '<b>Action Required:</b> '.e($this->action());

        return new PushoverMessage(
            title: 'API token sends many failed requests',
            level: 'warning',
            message: $message,
            buttons: [
                [
                    'text' => 'Manage API tokens',
                    'url' => $this->manageUrl,
                ],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        $description = $this->summary()."\n\n";
        $description .= "Last failed request: {$this->lastRequest}\n\n";
        $description .= '*Action Required:* '.$this->action()."\n\n";
        $description .= "Manage tokens: {$this->manageUrl}";

        return new SlackMessage(
            title: '🔑 API token sends many failed requests',
            description: $description,
            color: SlackMessage::warningColor(),
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => false,
            'message' => $this->summary(),
            'event' => 'api_token_failing',
            'token_name' => $this->tokenName,
            'failed_requests' => $this->failedRequests,
            'last_request' => $this->lastRequest,
            'url' => $this->manageUrl,
        ];
    }
}

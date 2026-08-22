<?php

namespace App\Notifications;

use App\Models\PersonalAccessToken;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ApiTokenExpiringNotification extends CustomEmailNotification
{
    protected string $tokenName;

    protected string $expiresAt;

    protected string $manageUrl;

    public function __construct(public PersonalAccessToken $token)
    {
        $this->onQueue('high');
        $this->tokenName = $token->name;
        $this->expiresAt = $token->expires_at?->format('Y-m-d H:i:s') ?? '';
        $this->manageUrl = route('security.api-tokens');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('api_token_expiring');
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject("Coolify: API token '{$this->tokenName}' expires in 24 hours");
        $mail->view('emails.api-token-expiring', [
            'tokenName' => $this->tokenName,
            'expiresAt' => $this->expiresAt,
            'manageUrl' => $this->manageUrl,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $message = new DiscordMessage(
            title: '🔑 API token expiring soon',
            description: "API token **{$this->tokenName}** expires on {$this->expiresAt}.\n\n**Action Required:** Rotate this token before it expires to avoid API outages.",
            color: DiscordMessage::warningColor(),
        );

        $message->addField('Manage tokens', "[Open Security settings]({$this->manageUrl})");

        return $message;
    }

    public function toTelegram(): array
    {
        $message = "Coolify: API token '{$this->tokenName}' expires on {$this->expiresAt}.\n\nAction Required: Rotate this token before it expires to avoid API outages.";

        return [
            'message' => $message,
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
        $message = "API token <b>{$this->tokenName}</b> expires on {$this->expiresAt}.<br/><br/>";
        $message .= '<b>Action Required:</b> Rotate this token before it expires to avoid API outages.';

        return new PushoverMessage(
            title: 'API token expiring soon',
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
        $description = "API token *{$this->tokenName}* expires on {$this->expiresAt}.\n\n";
        $description .= "*Action Required:* Rotate this token before it expires to avoid API outages.\n\n";
        $description .= "Manage tokens: {$this->manageUrl}";

        return new SlackMessage(
            title: '🔑 API token expiring soon',
            description: $description,
            color: SlackMessage::warningColor(),
        );
    }
}

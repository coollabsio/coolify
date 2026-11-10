<?php

namespace App\Notifications\Server;

use App\Actions\Server\CheckAutomaticSecurityUpdatesRun;
use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * @phpstan-import-type AutomaticSecurityUpdatesRun from CheckAutomaticSecurityUpdatesRun
 */
class AutomaticSecurityUpdatesFinished extends CustomEmailNotification
{
    public string $serverUrl;

    /**
     * @param  AutomaticSecurityUpdatesRun  $run
     */
    public function __construct(public Server $server, public array $run)
    {
        $this->onQueue('high');
        $this->serverUrl = base_url().'/server/'.$this->server->uuid.'/security/patches';
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_auto_update');
    }

    private function failed(): bool
    {
        return $this->run['failed'];
    }

    private function rebootRequired(): bool
    {
        return $this->run['reboot_required'] === true;
    }

    private function title(): string
    {
        return match (true) {
            $this->failed() => "[ACTION REQUIRED] Automatic security updates failed on {$this->server->name}",
            $this->rebootRequired() => "[ACTION REQUIRED] Automatic security updates installed on {$this->server->name}, reboot required",
            default => "Automatic security updates installed on {$this->server->name}",
        };
    }

    private function details(): string
    {
        $packages = $this->run['packages'];
        $lines = ["Run started at {$this->run['started_at']} (server time)."];
        if ($packages !== []) {
            $shown = array_slice($packages, 0, 20);
            $more = count($packages) - count($shown);
            $lines[] = ($this->failed() ? 'Packages to update' : 'Updated packages').' ('.count($packages).'): '.implode(', ', $shown).($more > 0 ? " and {$more} more" : '');
        }
        if ($this->failed() && $this->run['errors'] !== []) {
            $lines[] = 'Errors:';
            foreach (array_slice($this->run['errors'], 0, 5) as $error) {
                $lines[] = '- '.str($error)->limit(300);
            }
        }
        if ($this->rebootRequired()) {
            $lines[] = 'The server needs a reboot to apply the updates.';
        }

        return implode("\n", $lines);
    }

    public function toMail(): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject('Coolify: '.$this->title());
        $mail->view('emails.server-auto-security-updates', [
            'name' => $this->server->name,
            'failed' => $this->failed(),
            'started_at' => $this->run['started_at'],
            'packages' => $this->run['packages'],
            'error_lines' => array_slice($this->run['errors'], 0, 5),
            'reboot_required' => $this->rebootRequired(),
            'server_url' => $this->serverUrl,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        return new DiscordMessage(
            title: ($this->failed() ? ':cross_mark:' : ':white_check_mark:').' Coolify: '.$this->title(),
            description: $this->details()."\n\n[Manage Server]({$this->serverUrl})",
            color: match (true) {
                $this->failed() => DiscordMessage::errorColor(),
                $this->rebootRequired() => DiscordMessage::warningColor(),
                default => DiscordMessage::successColor(),
            },
        );
    }

    public function toTelegram(): array
    {
        return [
            'message' => 'Coolify: '.$this->title()."\n\n".$this->details(),
            'buttons' => [
                ['text' => 'Manage Server', 'url' => $this->serverUrl],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->failed() ? 'Automatic security updates failed' : 'Automatic security updates installed',
            level: match (true) {
                $this->failed() => 'error',
                $this->rebootRequired() => 'warning',
                default => 'success',
            },
            message: $this->title()."\n\n".$this->details(),
            buttons: [
                ['text' => 'Manage Server', 'url' => $this->serverUrl],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        return new SlackMessage(
            title: 'Coolify: '.$this->title(),
            description: $this->details()."\n\n<{$this->serverUrl}|Manage Server>",
            color: match (true) {
                $this->failed() => SlackMessage::errorColor(),
                $this->rebootRequired() => SlackMessage::warningColor(),
                default => SlackMessage::successColor(),
            },
        );
    }

    public function toWebhook(): array
    {
        return [
            'success' => ! $this->failed(),
            'message' => $this->failed() ? 'Automatic security updates failed' : 'Automatic security updates installed',
            'event' => 'server_auto_security_updates',
            'server_name' => $this->server->name,
            'server_uuid' => $this->server->uuid,
            'started_at' => $this->run['started_at'],
            'packages' => $this->run['packages'],
            'errors' => $this->run['errors'],
            'reboot_required' => $this->run['reboot_required'],
            'url' => $this->serverUrl,
        ];
    }
}

<?php

namespace App\Notifications\Server;

use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

class TraefikVersionOutdated extends CustomEmailNotification
{
    public function __construct(public Collection $servers)
    {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('traefik_outdated');
    }

    private function formatVersion(string $version): string
    {
        // Add 'v' prefix if not present for consistent display
        return str_starts_with($version, 'v') ? $version : "v{$version}";
    }

    public function toMail($notifiable = null): MailMessage
    {
        $mail = new MailMessage;
        $count = $this->servers->count();

        $mail->subject("Coolify: Traefik proxy outdated on {$count} server(s)");
        $mail->view('emails.traefik-version-outdated', [
            'servers' => $this->servers,
            'count' => $count,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $count = $this->servers->count();
        $hasUpgrades = $this->servers->contains(fn ($s) => ($s->outdatedInfo['type'] ?? 'patch_update') === 'minor_upgrade');

        $description = "**{$count} server(s)** running outdated Traefik proxy. Update recommended for security and features.\n\n";
        $description .= "*Based on actual running container version*\n\n";
        $description .= "**Affected servers:**\n";

        foreach ($this->servers as $server) {
            $info = $server->outdatedInfo ?? [];
            $current = $this->formatVersion($info['current'] ?? 'unknown');
            $latest = $this->formatVersion($info['latest'] ?? 'unknown');
            $type = ($info['type'] ?? 'patch_update') === 'patch_update' ? '(patch)' : '(upgrade)';
            $description .= "• {$server->name}: {$current} → {$latest} {$type}\n";
        }

        $description .= "\n⚠️ It is recommended to test before switching the production version.";

        if ($hasUpgrades) {
            $description .= "\n\n📖 **For major/minor upgrades**: Read the Traefik changelog before upgrading to understand breaking changes.";
        }

        return new DiscordMessage(
            title: ':warning: Coolify: Traefik proxy outdated',
            description: $description,
            color: DiscordMessage::warningColor(),
        );
    }

    public function toTelegram(): array
    {
        $count = $this->servers->count();
        $hasUpgrades = $this->servers->contains(fn ($s) => ($s->outdatedInfo['type'] ?? 'patch_update') === 'minor_upgrade');

        $message = "⚠️ Coolify: Traefik proxy outdated on {$count} server(s)!\n\n";
        $message .= "Update recommended for security and features.\n";
        $message .= "ℹ️ Based on actual running container version\n\n";
        $message .= "📊 Affected servers:\n";

        foreach ($this->servers as $server) {
            $info = $server->outdatedInfo ?? [];
            $current = $this->formatVersion($info['current'] ?? 'unknown');
            $latest = $this->formatVersion($info['latest'] ?? 'unknown');
            $type = ($info['type'] ?? 'patch_update') === 'patch_update' ? '(patch)' : '(upgrade)';
            $message .= "• {$server->name}: {$current} → {$latest} {$type}\n";
        }

        $message .= "\n⚠️ It is recommended to test before switching the production version.";

        if ($hasUpgrades) {
            $message .= "\n\n📖 For major/minor upgrades: Read the Traefik changelog before upgrading to understand breaking changes.";
        }

        return [
            'message' => $message,
            'buttons' => [],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $count = $this->servers->count();
        $hasUpgrades = $this->servers->contains(fn ($s) => ($s->outdatedInfo['type'] ?? 'patch_update') === 'minor_upgrade');

        $message = "Traefik proxy outdated on {$count} server(s)!\n";
        $message .= "Based on actual running container version\n\n";
        $message .= "Affected servers:\n";

        foreach ($this->servers as $server) {
            $info = $server->outdatedInfo ?? [];
            $current = $this->formatVersion($info['current'] ?? 'unknown');
            $latest = $this->formatVersion($info['latest'] ?? 'unknown');
            $type = ($info['type'] ?? 'patch_update') === 'patch_update' ? '(patch)' : '(upgrade)';
            $message .= "• {$server->name}: {$current} → {$latest} {$type}\n";
        }

        $message .= "\nIt is recommended to test before switching the production version.";

        if ($hasUpgrades) {
            $message .= "\n\nFor major/minor upgrades: Read the Traefik changelog before upgrading.";
        }

        return new PushoverMessage(
            title: 'Traefik proxy outdated',
            level: 'warning',
            message: $message,
        );
    }

    public function toSlack(): SlackMessage
    {
        $count = $this->servers->count();
        $hasUpgrades = $this->servers->contains(fn ($s) => ($s->outdatedInfo['type'] ?? 'patch_update') === 'minor_upgrade');

        $description = "Traefik proxy outdated on {$count} server(s)!\n";
        $description .= "_Based on actual running container version_\n\n";
        $description .= "*Affected servers:*\n";

        foreach ($this->servers as $server) {
            $info = $server->outdatedInfo ?? [];
            $current = $this->formatVersion($info['current'] ?? 'unknown');
            $latest = $this->formatVersion($info['latest'] ?? 'unknown');
            $type = ($info['type'] ?? 'patch_update') === 'patch_update' ? '(patch)' : '(upgrade)';
            $description .= "• `{$server->name}`: {$current} → {$latest} {$type}\n";
        }

        $description .= "\n:warning: It is recommended to test before switching the production version.";

        if ($hasUpgrades) {
            $description .= "\n\n:book: For major/minor upgrades: Read the Traefik changelog before upgrading to understand breaking changes.";
        }

        return new SlackMessage(
            title: 'Coolify: Traefik proxy outdated',
            description: $description,
            color: SlackMessage::warningColor()
        );
    }

    public function toWebhook(): array
    {
        $servers = $this->servers->map(function ($server) {
            $info = $server->outdatedInfo ?? [];

            return [
                'name' => $server->name,
                'uuid' => $server->uuid,
                'current_version' => $info['current'] ?? 'unknown',
                'latest_version' => $info['latest'] ?? 'unknown',
                'update_type' => $info['type'] ?? 'patch_update',
            ];
        })->toArray();

        return [
            'success' => false,
            'message' => 'Traefik proxy outdated',
            'event' => 'traefik_version_outdated',
            'affected_servers_count' => $this->servers->count(),
            'servers' => $servers,
        ];
    }
}

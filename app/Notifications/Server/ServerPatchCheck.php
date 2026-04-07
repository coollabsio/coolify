<?php

namespace App\Notifications\Server;

use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;

class ServerPatchCheck extends CustomEmailNotification
{
    public function __construct(
        public Collection $servers,
        public bool $bundledOnly = false,
        public bool $unbundledOnly = false,
    ) {
        $this->onQueue('high');
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_patch', bundledOnly: $this->bundledOnly, unbundledOnly: $this->unbundledOnly);
    }

    private function serverUrl(object $server): string
    {
        return base_url().'/server/'.$server->uuid.'/security/patches';
    }

    private function hasErrors(): bool
    {
        return $this->servers->contains(fn ($s) => isset($s->patch_check_data['error']));
    }

    private function errorServers(): Collection
    {
        return $this->servers->filter(fn ($s) => isset($s->patch_check_data['error']));
    }

    private function updateServers(): Collection
    {
        return $this->servers->filter(fn ($s) => ! isset($s->patch_check_data['error']));
    }

    public function toMail($notifiable = null): MailMessage
    {
        $mail = new MailMessage;
        $count = $this->servers->count();

        $serversWithUrls = $this->servers->map(function ($server) {
            return [
                'name' => $server->name,
                'uuid' => $server->uuid,
                'url' => $this->serverUrl($server),
                'patchData' => $server->patch_check_data,
            ];
        });

        $mail->subject("Coolify: Server patches available on {$count} server(s)");
        $mail->view('emails.server-patches-bundled', [
            'servers' => $serversWithUrls,
            'count' => $count,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        $count = $this->servers->count();
        $description = "**{$count} server(s)** have package updates or errors.\n\n";

        foreach ($this->errorServers() as $server) {
            $data = $server->patch_check_data;
            $description .= ":x: **{$server->name}** — failed to check updates\n";
            $description .= "  Error: {$data['error']}\n";
        }

        foreach ($this->updateServers() as $server) {
            $data = $server->patch_check_data;
            $total = $data['total_updates'] ?? 0;
            $description .= ":warning: **{$server->name}** — {$total} updates available\n";

            $updates = $data['updates'] ?? [];
            $criticalPackages = collect($updates)->filter(fn ($u) => str_contains(strtolower($u['package']), 'docker') ||
                str_contains(strtolower($u['package']), 'kernel') ||
                str_contains(strtolower($u['package']), 'openssh') ||
                str_contains(strtolower($u['package']), 'ssl')
            );

            if ($criticalPackages->isNotEmpty()) {
                $description .= "  ⚠ {$criticalPackages->count()} critical package(s)\n";
            }
        }

        return new DiscordMessage(
            title: ':warning: Coolify: [ACTION REQUIRED] Server patches available',
            description: $description,
            color: DiscordMessage::errorColor(),
        );
    }

    public function toTelegram(): array
    {
        $count = $this->servers->count();
        $message = "🔧 Coolify: [ACTION REQUIRED] Server patches available on {$count} server(s)!\n\n";

        foreach ($this->errorServers() as $server) {
            $data = $server->patch_check_data;
            $message .= "❌ {$server->name} — failed to check updates\n";
            $message .= "  Error: {$data['error']}\n";
        }

        foreach ($this->updateServers() as $server) {
            $data = $server->patch_check_data;
            $total = $data['total_updates'] ?? 0;
            $message .= "📦 {$server->name} — {$total} updates available\n";

            $updates = $data['updates'] ?? [];
            $criticalPackages = collect($updates)->filter(fn ($u) => str_contains(strtolower($u['package']), 'docker') ||
                str_contains(strtolower($u['package']), 'kernel') ||
                str_contains(strtolower($u['package']), 'openssh') ||
                str_contains(strtolower($u['package']), 'ssl')
            );

            if ($criticalPackages->isNotEmpty()) {
                $message .= "  ⚠️ {$criticalPackages->count()} critical package(s)\n";
            }
        }

        return [
            'message' => $message,
            'buttons' => [],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        $count = $this->servers->count();
        $message = "Server patches available on {$count} server(s)!\n\n";

        foreach ($this->errorServers() as $server) {
            $data = $server->patch_check_data;
            $message .= "[ERROR] {$server->name} — {$data['error']}\n";
        }

        foreach ($this->updateServers() as $server) {
            $data = $server->patch_check_data;
            $total = $data['total_updates'] ?? 0;
            $message .= "{$server->name} — {$total} updates\n";
        }

        return new PushoverMessage(
            title: 'Server patches available',
            level: 'error',
            message: $message,
        );
    }

    public function toSlack(): SlackMessage
    {
        $count = $this->servers->count();
        $description = "Server patches available on {$count} server(s)!\n\n";

        foreach ($this->errorServers() as $server) {
            $data = $server->patch_check_data;
            $description .= ":x: *{$server->name}* — failed to check updates\n";
            $description .= "  Error: `{$data['error']}`\n";
        }

        foreach ($this->updateServers() as $server) {
            $data = $server->patch_check_data;
            $total = $data['total_updates'] ?? 0;
            $description .= ":warning: *{$server->name}* — {$total} updates available\n";

            $updates = $data['updates'] ?? [];
            $criticalPackages = collect($updates)->filter(fn ($u) => str_contains(strtolower($u['package']), 'docker') ||
                str_contains(strtolower($u['package']), 'kernel') ||
                str_contains(strtolower($u['package']), 'openssh') ||
                str_contains(strtolower($u['package']), 'ssl')
            );

            if ($criticalPackages->isNotEmpty()) {
                $description .= "  :warning: {$criticalPackages->count()} critical package(s)\n";
            }
        }

        return new SlackMessage(
            title: 'Coolify: [ACTION REQUIRED] Server patches available',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }

    public function toWebhook(): array
    {
        $servers = $this->servers->map(function ($server) {
            $data = $server->patch_check_data;

            if (isset($data['error'])) {
                return [
                    'server_name' => $server->name,
                    'server_uuid' => $server->uuid,
                    'event' => 'server_patch_check_error',
                    'error' => $data['error'],
                    'os_id' => $data['osId'] ?? 'unknown',
                    'package_manager' => $data['package_manager'] ?? 'unknown',
                ];
            }

            $updates = $data['updates'] ?? [];
            $criticalPackages = collect($updates)->filter(fn ($u) => str_contains(strtolower($u['package']), 'docker') ||
                str_contains(strtolower($u['package']), 'kernel') ||
                str_contains(strtolower($u['package']), 'openssh') ||
                str_contains(strtolower($u['package']), 'ssl')
            );

            return [
                'server_name' => $server->name,
                'server_uuid' => $server->uuid,
                'event' => 'server_patch_check',
                'total_updates' => $data['total_updates'] ?? 0,
                'os_id' => $data['osId'] ?? 'unknown',
                'package_manager' => $data['package_manager'] ?? 'unknown',
                'updates' => $updates,
                'critical_packages_count' => $criticalPackages->count(),
            ];
        })->toArray();

        return [
            'success' => false,
            'message' => 'Server patches available',
            'event' => 'server_patch_check',
            'affected_servers_count' => $this->servers->count(),
            'servers' => $servers,
        ];
    }
}

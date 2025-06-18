<?php

namespace App\Notifications\Server;

use App\Models\Server;
use App\Notifications\CustomEmailNotification;
use App\Notifications\Dto\DiscordMessage;
use App\Notifications\Dto\PushoverMessage;
use App\Notifications\Dto\SlackMessage;
use Illuminate\Notifications\Messages\MailMessage;

class ServerPatchCheck extends CustomEmailNotification
{
    public string $serverUrl;

    public function __construct(public Server $server, public array $patchData)
    {
        $this->onQueue('high');
        $this->serverUrl = route('server.security.patches', ['server_uuid' => $this->server->uuid]);
        if (isDev()) {
            $this->serverUrl = 'https://staging-but-dev.coolify.io/server/'.$this->server->uuid.'/security/patches';
        }
    }

    public function via(object $notifiable): array
    {
        return $notifiable->getEnabledChannels('server_patch');
    }

    public function toMail($notifiable = null): MailMessage
    {
        $mail = new MailMessage;

        // Handle error case
        if (isset($this->patchData['error'])) {
            $mail->subject("Coolify: [ERROR] Failed to check patches on {$this->server->name}");
            $mail->view('emails.server-patches-error', [
                'name' => $this->server->name,
                'error' => $this->patchData['error'],
                'osId' => $this->patchData['osId'] ?? 'unknown',
                'package_manager' => $this->patchData['package_manager'] ?? 'unknown',
                'server_url' => $this->serverUrl,
            ]);

            return $mail;
        }

        $totalUpdates = $this->patchData['total_updates'] ?? 0;
        $mail->subject("Coolify: [ACTION REQUIRED] {$totalUpdates} server patches available on {$this->server->name}");
        $mail->view('emails.server-patches', [
            'name' => $this->server->name,
            'total_updates' => $totalUpdates,
            'updates' => $this->patchData['updates'] ?? [],
            'osId' => $this->patchData['osId'] ?? 'unknown',
            'package_manager' => $this->patchData['package_manager'] ?? 'unknown',
            'server_url' => $this->serverUrl,
        ]);

        return $mail;
    }

    public function toDiscord(): DiscordMessage
    {
        // Handle error case
        if (isset($this->patchData['error'])) {
            $osId = $this->patchData['osId'] ?? 'unknown';
            $packageManager = $this->patchData['package_manager'] ?? 'unknown';
            $error = $this->patchData['error'];

            $description = "**Failed to check for updates** on server {$this->server->name}\n\n";
            $description .= "**Error Details:**\n";
            $description .= '• OS: '.ucfirst($osId)."\n";
            $description .= "• Package Manager: {$packageManager}\n";
            $description .= "• Error: {$error}\n\n";
            $description .= "[Manage Server]($this->serverUrl)";

            return new DiscordMessage(
                title: ':x: Coolify: [ERROR] Failed to check patches on '.$this->server->name,
                description: $description,
                color: DiscordMessage::errorColor(),
            );
        }

        $totalUpdates = $this->patchData['total_updates'] ?? 0;
        $updates = $this->patchData['updates'] ?? [];
        $osId = $this->patchData['osId'] ?? 'unknown';
        $packageManager = $this->patchData['package_manager'] ?? 'unknown';

        $description = "**{$totalUpdates} package updates** available for server {$this->server->name}\n\n";
        $description .= "**Summary:**\n";
        $description .= '• OS: '.ucfirst($osId)."\n";
        $description .= "• Package Manager: {$packageManager}\n";
        $description .= "• Total Updates: {$totalUpdates}\n\n";

        // Show first few packages
        if (count($updates) > 0) {
            $description .= "**Sample Updates:**\n";
            $sampleUpdates = array_slice($updates, 0, 5);
            foreach ($sampleUpdates as $update) {
                $description .= "• {$update['package']}: {$update['current_version']} → {$update['new_version']}\n";
            }
            if (count($updates) > 5) {
                $description .= '• ... and '.(count($updates) - 5)." more packages\n";
            }

            // Check for critical packages
            $criticalPackages = collect($updates)->filter(function ($update) {
                return str_contains(strtolower($update['package']), 'docker') ||
                    str_contains(strtolower($update['package']), 'kernel') ||
                    str_contains(strtolower($update['package']), 'openssh') ||
                    str_contains(strtolower($update['package']), 'ssl');
            });

            if ($criticalPackages->count() > 0) {
                $description .= "\n **Critical packages detected** ({$criticalPackages->count()} packages may require restarts)";
            }
            $description .= "\n [Manage Server Patches]($this->serverUrl)";
        }

        return new DiscordMessage(
            title: ':warning: Coolify: [ACTION REQUIRED] Server patches available on '.$this->server->name,
            description: $description,
            color: DiscordMessage::errorColor(),
        );

    }

    public function toTelegram(): array
    {
        // Handle error case
        if (isset($this->patchData['error'])) {
            $osId = $this->patchData['osId'] ?? 'unknown';
            $packageManager = $this->patchData['package_manager'] ?? 'unknown';
            $error = $this->patchData['error'];

            $message = "❌ Coolify: [ERROR] Failed to check patches on {$this->server->name}!\n\n";
            $message .= "📊 Error Details:\n";
            $message .= '• OS: '.ucfirst($osId)."\n";
            $message .= "• Package Manager: {$packageManager}\n";
            $message .= "• Error: {$error}\n\n";

            return [
                'message' => $message,
                'buttons' => [
                    [
                        'text' => 'Manage Server',
                        'url' => $this->serverUrl,
                    ],
                ],
            ];
        }

        $totalUpdates = $this->patchData['total_updates'] ?? 0;
        $updates = $this->patchData['updates'] ?? [];
        $osId = $this->patchData['osId'] ?? 'unknown';
        $packageManager = $this->patchData['package_manager'] ?? 'unknown';

        $message = "🔧 Coolify: [ACTION REQUIRED] {$totalUpdates} server patches available on {$this->server->name}!\n\n";
        $message .= "📊 Summary:\n";
        $message .= '• OS: '.ucfirst($osId)."\n";
        $message .= "• Package Manager: {$packageManager}\n";
        $message .= "• Total Updates: {$totalUpdates}\n\n";

        if (count($updates) > 0) {
            $message .= "📦 Sample Updates:\n";
            $sampleUpdates = array_slice($updates, 0, 5);
            foreach ($sampleUpdates as $update) {
                $message .= "• {$update['package']}: {$update['current_version']} → {$update['new_version']}\n";
            }
            if (count($updates) > 5) {
                $message .= '• ... and '.(count($updates) - 5)." more packages\n";
            }

            // Check for critical packages
            $criticalPackages = collect($updates)->filter(function ($update) {
                return str_contains(strtolower($update['package']), 'docker') ||
                    str_contains(strtolower($update['package']), 'kernel') ||
                    str_contains(strtolower($update['package']), 'openssh') ||
                    str_contains(strtolower($update['package']), 'ssl');
            });

            if ($criticalPackages->count() > 0) {
                $message .= "\n⚠️ Critical packages detected: {$criticalPackages->count()} packages may require restarts\n";
                foreach ($criticalPackages->take(3) as $package) {
                    $message .= "• {$package['package']}: {$package['current_version']} → {$package['new_version']}\n";
                }
                if ($criticalPackages->count() > 3) {
                    $message .= '• ... and '.($criticalPackages->count() - 3)." more critical packages\n";
                }
            }
        }

        return [
            'message' => $message,
            'buttons' => [
                [
                    'text' => 'Manage Server Patches',
                    'url' => $this->serverUrl,
                ],
            ],
        ];
    }

    public function toPushover(): PushoverMessage
    {
        // Handle error case
        if (isset($this->patchData['error'])) {
            $osId = $this->patchData['osId'] ?? 'unknown';
            $packageManager = $this->patchData['package_manager'] ?? 'unknown';
            $error = $this->patchData['error'];

            $message = "[ERROR] Failed to check patches on {$this->server->name}!\n\n";
            $message .= "Error Details:\n";
            $message .= '• OS: '.ucfirst($osId)."\n";
            $message .= "• Package Manager: {$packageManager}\n";
            $message .= "• Error: {$error}\n\n";

            return new PushoverMessage(
                title: 'Server patch check failed',
                level: 'error',
                message: $message,
                buttons: [
                    [
                        'text' => 'Manage Server',
                        'url' => $this->serverUrl,
                    ],
                ],
            );
        }

        $totalUpdates = $this->patchData['total_updates'] ?? 0;
        $updates = $this->patchData['updates'] ?? [];
        $osId = $this->patchData['osId'] ?? 'unknown';
        $packageManager = $this->patchData['package_manager'] ?? 'unknown';

        $message = "[ACTION REQUIRED] {$totalUpdates} server patches available on {$this->server->name}!\n\n";
        $message .= "Summary:\n";
        $message .= '• OS: '.ucfirst($osId)."\n";
        $message .= "• Package Manager: {$packageManager}\n";
        $message .= "• Total Updates: {$totalUpdates}\n\n";

        if (count($updates) > 0) {
            $message .= "Sample Updates:\n";
            $sampleUpdates = array_slice($updates, 0, 3);
            foreach ($sampleUpdates as $update) {
                $message .= "• {$update['package']}: {$update['current_version']} → {$update['new_version']}\n";
            }
            if (count($updates) > 3) {
                $message .= '• ... and '.(count($updates) - 3)." more packages\n";
            }

            // Check for critical packages
            $criticalPackages = collect($updates)->filter(function ($update) {
                return str_contains(strtolower($update['package']), 'docker') ||
                    str_contains(strtolower($update['package']), 'kernel') ||
                    str_contains(strtolower($update['package']), 'openssh') ||
                    str_contains(strtolower($update['package']), 'ssl');
            });

            if ($criticalPackages->count() > 0) {
                $message .= "\nCritical packages detected: {$criticalPackages->count()} may require restarts";
            }
        }

        return new PushoverMessage(
            title: 'Server patches available',
            level: 'error',
            message: $message,
            buttons: [
                [
                    'text' => 'Manage Server Patches',
                    'url' => $this->serverUrl,
                ],
            ],
        );
    }

    public function toSlack(): SlackMessage
    {
        // Handle error case
        if (isset($this->patchData['error'])) {
            $osId = $this->patchData['osId'] ?? 'unknown';
            $packageManager = $this->patchData['package_manager'] ?? 'unknown';
            $error = $this->patchData['error'];

            $description = "Failed to check patches on '{$this->server->name}'!\n\n";
            $description .= "*Error Details:*\n";
            $description .= '• OS: '.ucfirst($osId)."\n";
            $description .= "• Package Manager: {$packageManager}\n";
            $description .= "• Error: `{$error}`\n\n";
            $description .= "\n:link: <{$this->serverUrl}|Manage Server>";

            return new SlackMessage(
                title: 'Coolify: [ERROR] Server patch check failed',
                description: $description,
                color: SlackMessage::errorColor()
            );
        }

        $totalUpdates = $this->patchData['total_updates'] ?? 0;
        $updates = $this->patchData['updates'] ?? [];
        $osId = $this->patchData['osId'] ?? 'unknown';
        $packageManager = $this->patchData['package_manager'] ?? 'unknown';

        $description = "{$totalUpdates} server patches available on '{$this->server->name}'!\n\n";
        $description .= "*Summary:*\n";
        $description .= '• OS: '.ucfirst($osId)."\n";
        $description .= "• Package Manager: {$packageManager}\n";
        $description .= "• Total Updates: {$totalUpdates}\n\n";

        if (count($updates) > 0) {
            $description .= "*Sample Updates:*\n";
            $sampleUpdates = array_slice($updates, 0, 5);
            foreach ($sampleUpdates as $update) {
                $description .= "• `{$update['package']}`: {$update['current_version']} → {$update['new_version']}\n";
            }
            if (count($updates) > 5) {
                $description .= '• ... and '.(count($updates) - 5)." more packages\n";
            }

            // Check for critical packages
            $criticalPackages = collect($updates)->filter(function ($update) {
                return str_contains(strtolower($update['package']), 'docker') ||
                    str_contains(strtolower($update['package']), 'kernel') ||
                    str_contains(strtolower($update['package']), 'openssh') ||
                    str_contains(strtolower($update['package']), 'ssl');
            });

            if ($criticalPackages->count() > 0) {
                $description .= "\n:warning: *Critical packages detected:* {$criticalPackages->count()} packages may require restarts\n";
                foreach ($criticalPackages->take(3) as $package) {
                    $description .= "• `{$package['package']}`: {$package['current_version']} → {$package['new_version']}\n";
                }
                if ($criticalPackages->count() > 3) {
                    $description .= '• ... and '.($criticalPackages->count() - 3)." more critical packages\n";
                }
            }
        }

        $description .= "\n:link: <{$this->serverUrl}|Manage Server Patches>";

        return new SlackMessage(
            title: 'Coolify: [ACTION REQUIRED] Server patches available',
            description: $description,
            color: SlackMessage::errorColor()
        );
    }
}

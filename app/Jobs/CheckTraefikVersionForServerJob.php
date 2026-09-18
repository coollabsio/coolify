<?php

namespace App\Jobs;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Events\ProxyStatusChangedUI;
use App\Models\Server;
use App\Notifications\Server\TraefikVersionOutdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CheckTraefikVersionForServerJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 60;

    public $uniqueFor = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Server $server,
        public array $traefikVersions
    ) {}

    public function uniqueId(): string
    {
        return $this->server->uuid;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->server->refresh();

        if ($this->server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            $this->clearTraefikVersionState();

            return;
        }

        if ($this->server->proxy->get('status') !== ProxyStatus::RUNNING->value) {
            return;
        }

        // Detect current version (makes SSH call)
        $currentVersion = $this->detectCurrentVersion();

        if (! $currentVersion) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        $this->server->update(['detected_traefik_version' => $currentVersion]);

        // Check if image tag is 'latest' by inspecting the image (makes SSH call)
        $imageTag = $this->detectImageTag();

        // Handle empty/null response from SSH command
        if (blank($imageTag)) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        if (str_contains(strtolower(trim($imageTag)), ':latest')) {
            $this->resolveOutdatedInfo();
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        // Parse current version to extract major.minor.patch
        $current = ltrim($currentVersion, 'v');
        if (! preg_match('/^(\d+\.\d+)\.(\d+)$/', $current, $matches)) {
            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        $currentBranch = $matches[1]; // e.g., "3.6"

        // Find the latest version for this branch
        $latestForBranch = $this->traefikVersions["v{$currentBranch}"] ?? null;

        if (! $latestForBranch) {
            // User is on a branch we don't track - check if newer branches exist
            $newerBranchInfo = $this->getNewerBranchInfo($currentBranch);

            if ($newerBranchInfo) {
                $this->storeOutdatedInfo($current, $newerBranchInfo['latest'], 'minor_upgrade', $newerBranchInfo['target']);
            }

            ProxyStatusChangedUI::dispatch($this->server->team_id);

            return;
        }

        // Compare patch version within the same branch
        $latest = ltrim($latestForBranch, 'v');

        // Always check for newer branches first
        $newerBranchInfo = $this->getNewerBranchInfo($currentBranch);

        if ($newerBranchInfo) {
            $this->storeOutdatedInfo($current, $newerBranchInfo['latest'], 'minor_upgrade', $newerBranchInfo['target']);
        } elseif (version_compare($current, $latest, '<')) {
            $this->storeOutdatedInfo($current, $latest, 'patch_update');
        } else {
            // Fully up to date
            $this->resolveOutdatedInfo();
        }

        // Dispatch UI update event so warning state refreshes in real-time
        ProxyStatusChangedUI::dispatch($this->server->team_id);
    }

    protected function detectCurrentVersion(): ?string
    {
        return getTraefikVersionFromDockerCompose($this->server);
    }

    protected function detectImageTag(): ?string
    {
        return instant_remote_process([
            "docker inspect coolify-proxy --format '{{.Config.Image}}' 2>/dev/null",
        ], $this->server, false);
    }

    private function clearTraefikVersionState(): void
    {
        $this->server->update([
            'detected_traefik_version' => null,
            'traefik_outdated_info' => null,
        ]);
    }

    private function resolveOutdatedInfo(): void
    {
        $this->server->update(['traefik_outdated_info' => null]);
    }

    /**
     * Get information about newer branches if available.
     */
    private function getNewerBranchInfo(string $currentBranch): ?array
    {
        $newestBranch = null;
        $newestVersion = null;

        foreach ($this->traefikVersions as $branch => $version) {
            $branchNum = ltrim($branch, 'v');
            if (version_compare($branchNum, $currentBranch, '>')) {
                if (! $newestVersion || version_compare($version, $newestVersion, '>')) {
                    $newestBranch = $branchNum;
                    $newestVersion = $version;
                }
            }
        }

        if ($newestVersion) {
            return [
                'target' => "v{$newestBranch}",
                'latest' => ltrim($newestVersion, 'v'),
            ];
        }

        return null;
    }

    /**
     * Store outdated information and atomically reserve a notification when due.
     */
    private function storeOutdatedInfo(string $current, string $latest, string $type, ?string $upgradeTarget = null, ?array $newerBranchInfo = null): void
    {
        $detectedState = [
            'current' => $current,
            'latest' => $latest,
            'type' => $type,
        ];

        // For minor upgrades, add the upgrade_target field (e.g., "v3.6")
        if ($type === 'minor_upgrade' && $upgradeTarget) {
            $detectedState['upgrade_target'] = $upgradeTarget;
        }

        // If there's a newer branch available (even for patch updates), include that info
        if ($newerBranchInfo) {
            $detectedState['newer_branch_target'] = $newerBranchInfo['target'];
            $detectedState['newer_branch_latest'] = $newerBranchInfo['latest'];
        }

        $canNotify = $type !== 'patch_update'
            && $this->server->team?->getEnabledChannels('traefik_outdated') !== [];
        $notificationInfo = DB::transaction(function () use ($detectedState, $canNotify): ?array {
            $server = Server::query()->lockForUpdate()->find($this->server->getKey());

            if (! $server) {
                return null;
            }

            $now = now();
            $previousInfo = $server->traefik_outdated_info ?? [];
            $fingerprint = $this->fingerprint($server, $detectedState);
            $sameFingerprint = isset($previousInfo['fingerprint'])
                && is_string($previousInfo['fingerprint'])
                && hash_equals($previousInfo['fingerprint'], $fingerprint);
            $matchingLegacyState = ! isset($previousInfo['fingerprint'])
                && $this->matchesDetectedState($previousInfo, $detectedState);
            $sameAlertState = $sameFingerprint || $matchingLegacyState;
            $lastNotifiedAt = $sameFingerprint
                ? $this->parseNotificationTime($previousInfo['last_notified_at'] ?? null)
                : null;
            $reminderAnchor = $lastNotifiedAt ?? ($matchingLegacyState ? $now : null);
            $notificationDue = $canNotify && (
                ! $sameAlertState
                || ! $reminderAnchor
                || $now->greaterThanOrEqualTo($reminderAnchor->copy()->addDay())
            );

            $outdatedInfo = [
                ...$detectedState,
                'checked_at' => $now->toIso8601String(),
                'fingerprint' => $fingerprint,
                'first_seen_at' => $sameAlertState
                    ? ($previousInfo['first_seen_at'] ?? $now->toIso8601String())
                    : $now->toIso8601String(),
                'last_seen_at' => $now->toIso8601String(),
                'last_notified_at' => $notificationDue
                    ? $now->toIso8601String()
                    : ($previousInfo['last_notified_at'] ?? ($matchingLegacyState ? $now->toIso8601String() : null)),
            ];

            $server->update(['traefik_outdated_info' => $outdatedInfo]);
            $this->server = $server;

            return $notificationDue ? $outdatedInfo : null;
        });

        if ($notificationInfo) {
            $this->sendNotification($notificationInfo);
        }
    }

    private function fingerprint(Server $server, array $detectedState): string
    {
        $canonicalState = [
            'category' => 'traefik_outdated',
            'severity' => 'warning',
            'server_uuid' => $server->uuid,
            'current' => $detectedState['current'],
            'latest' => $detectedState['latest'],
            'type' => $detectedState['type'],
            'upgrade_target' => $detectedState['upgrade_target'] ?? null,
            'newer_branch_target' => $detectedState['newer_branch_target'] ?? null,
            'newer_branch_latest' => $detectedState['newer_branch_latest'] ?? null,
        ];

        return hash('sha256', json_encode($canonicalState, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function matchesDetectedState(array $previousInfo, array $detectedState): bool
    {
        if (! isset($previousInfo['current'], $previousInfo['latest'], $previousInfo['type'])) {
            return false;
        }

        foreach (['current', 'latest', 'type', 'upgrade_target', 'newer_branch_target', 'newer_branch_latest'] as $key) {
            if (($previousInfo[$key] ?? null) !== ($detectedState[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function parseNotificationTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Send notification to team about outdated Traefik.
     */
    private function sendNotification(array $outdatedInfo): void
    {
        // Keep notification-only data off the persisted model instance.
        $notificationServer = clone $this->server;
        $notificationServer->outdatedInfo = $outdatedInfo;

        // Get the team and send notification
        $team = $this->server->team()->first();

        if ($team) {
            $team->notify(
                (new TraefikVersionOutdated(collect([$notificationServer])))->afterCommit()
            );
        }
    }
}

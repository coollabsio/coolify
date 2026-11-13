<?php

namespace App\Services\ProjectConfig;

use App\Enums\ConfigRevisionStatus;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;

/**
 * The coolify.json sync state of an environment, in the words the UI shows.
 */
final class ConfigSyncStatus
{
    /**
     * @return array{state: string, tone: string, label: string, needs_attention: bool, message: ?string, commit: ?string, revision_uuid: ?string}|null
     */
    public static function forEnvironment(Environment $environment): ?array
    {
        $link = $environment->relationLoaded('configLink') ? $environment->configLink : $environment->configLink()->first();

        return $link === null ? null : self::forLink($link);
    }

    /**
     * @return array{state: string, tone: string, label: string, needs_attention: bool, message: ?string, commit: ?string, revision_uuid: ?string}
     */
    public static function forLink(EnvironmentConfigLink $link): array
    {
        if ($link->isSuspended()) {
            return self::make('paused', 'neutral', 'Sync paused', null, null);
        }

        $revision = $link->revisions()
            ->whereNotIn('status', [ConfigRevisionStatus::Skipped->value, ConfigRevisionStatus::Superseded->value])
            ->latest('id')
            ->first();
        if ($revision === null) {
            return self::make('waiting_first', 'neutral', $link->isGit() ? 'Waiting for the first push' : 'Waiting for the first file', null, null);
        }

        $commit = $revision->commit_sha ? substr($revision->commit_sha, 0, 7) : null;

        return match ($revision->status) {
            ConfigRevisionStatus::Applied => self::make('synced', 'success', 'Synced'.($commit ? " · {$commit}" : ''), null, $revision),
            ConfigRevisionStatus::Applying, ConfigRevisionStatus::Planned => self::make('applying', 'progress', 'Applying'.($commit ? " {$commit}" : ''), null, $revision),
            ConfigRevisionStatus::WaitingApproval => self::make('waiting', 'warning', self::approvalLabel($revision), self::approvalMessage($revision), $revision),
            default => self::make('failed', 'error', 'Sync failed', 'The last sync failed: '.($revision->error ?: 'unknown error.'), $revision),
        };
    }

    private static function approvalLabel(EnvironmentConfigRevision $revision): string
    {
        $count = count($revision->plan['changes'] ?? []);

        return $count === 1 ? '1 change needs approval' : "{$count} changes need approval";
    }

    private static function approvalMessage(EnvironmentConfigRevision $revision): string
    {
        $changes = collect($revision->plan['changes'] ?? []);
        $removed = $changes->where('kind', 'remove')->pluck('key')->unique()->values();
        $others = $changes->where('kind', '!=', 'remove')->count();
        $parts = [];
        if ($removed->isNotEmpty()) {
            $parts[] = 'removes '.$removed->implode(', ');
        }
        if ($others > 0) {
            $parts[] = 'changes '.$others.' '.($others === 1 ? 'setting' : 'settings');
        }
        $who = $revision->commit_sha ? 'Commit '.substr($revision->commit_sha, 0, 7) : 'This file';

        return $who.' '.implode(' and ', $parts ?: ['has changes']).'. Deploys from this push wait.';
    }

    private static function make(string $state, string $tone, string $label, ?string $message, ?EnvironmentConfigRevision $revision): array
    {
        return [
            'state' => $state,
            'tone' => $tone,
            'label' => $label,
            'needs_attention' => in_array($state, ['waiting', 'failed'], true),
            'message' => $message,
            'commit' => $revision?->commit_sha ? substr($revision->commit_sha, 0, 7) : null,
            'revision_uuid' => $revision?->uuid,
        ];
    }
}

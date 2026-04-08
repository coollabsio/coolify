<?php

namespace App\Jobs;

use App\Models\Team;
use App\Models\UpdateNotificationReportState;
use App\Notifications\MasterUpdateReport;
use App\Services\Notifications\MasterUpdateReportBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SendMasterUpdateReportJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 1800;

    public function handle(MasterUpdateReportBuilder $builder): void
    {
        $timezone = instanceSettings()->instance_timezone ?: config('app.timezone');
        $now = now($timezone);

        Team::query()
            ->with(['emailNotificationSettings', 'servers'])
            ->chunkById(100, function (Collection $teams) use ($builder, $now): void {
                foreach ($teams as $team) {
                    $settings = $team->emailNotificationSettings;

                    if (! $settings || ! $settings->isEnabled() || ! $settings->master_update_report_email_notifications) {
                        continue;
                    }

                    if (! $this->isDueToday($settings->master_update_report_frequency ?? 'weekly', $settings->master_update_report_day ?? 'monday', $now)) {
                        continue;
                    }

                    $items = collect($builder->collect($team));
                    if ($items->isEmpty()) {
                        continue;
                    }

                    $pendingItems = $this->filterPendingItems($team, $items);
                    if ($pendingItems->isEmpty()) {
                        continue;
                    }

                    $sections = $this->buildSections($pendingItems);

                    try {
                        $team->notifyNow(new MasterUpdateReport($sections, $pendingItems->count()));
                        $this->persistStates($team, $pendingItems);
                    } catch (\Throwable $e) {
                        Log::error('Failed to send master update report.', [
                            'team_id' => $team->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    protected function isDueToday(string $frequency, string $day, $now): bool
    {
        return match ($frequency) {
            'daily' => true,
            'weekly' => strtolower($now->englishDayOfWeek) === strtolower($day),
            default => false,
        };
    }

    protected function filterPendingItems(Team $team, Collection $items): Collection
    {
        $existingStates = UpdateNotificationReportState::where('team_id', $team->id)
            ->get()
            ->keyBy(fn ($state) => "{$state->item_type}:{$state->item_key}");

        return $items->filter(function (array $item) use ($existingStates) {
            $state = $existingStates->get("{$item['item_type']}:{$item['item_key']}");

            return ! $state || $state->fingerprint !== $item['fingerprint'];
        })->values();
    }

    protected function buildSections(Collection $items): array
    {
        $serverPatches = $items
            ->where('section', 'server_patches')
            ->groupBy('group_key')
            ->map(function (Collection $packages) {
                return [
                    'label' => $packages->first()['group_label'],
                    'url' => $packages->first()['group_url'],
                    'packages' => $packages->map(fn ($item) => [
                        'label' => $item['label'],
                        'summary' => $item['summary'],
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        return [
            'coolify_upgrades' => $items->where('section', 'coolify_upgrades')->values()->all(),
            'proxy_upgrades' => $items->where('section', 'proxy_upgrades')->values()->all(),
            'server_patches' => $serverPatches,
            'container_image_updates' => $items->where('section', 'container_image_updates')->values()->all(),
        ];
    }

    protected function persistStates(Team $team, Collection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $timestamp = now();
        $rows = $items
            ->map(fn (array $item) => [
                'team_id' => $team->id,
                'item_type' => $item['item_type'],
                'item_key' => $item['item_key'],
                'fingerprint' => $item['fingerprint'],
                'last_reported_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        UpdateNotificationReportState::query()->upsert(
            $rows,
            ['team_id', 'item_type', 'item_key'],
            ['fingerprint', 'last_reported_at', 'updated_at']
        );
    }
}

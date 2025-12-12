<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\ChangelogService;
use Livewire\Component;

class Upgrade extends Component
{
    public bool $updateInProgress = false;

    public bool $isUpgradeAvailable = false;

    public string $latestVersion = '';

    public string $currentVersion = '';

    public array $changelogEntries = [];

    protected $listeners = ['updateAvailable' => 'checkUpdate'];

    public function mount()
    {
        $this->currentVersion = config('constants.coolify.version');
    }

    public function checkUpdate()
    {
        try {
            $this->latestVersion = get_latest_version_of_coolify();
            $this->currentVersion = config('constants.coolify.version');
            $this->isUpgradeAvailable = data_get(InstanceSettings::get(), 'new_version_available', false);
            if (isDev()) {
                $this->isUpgradeAvailable = true;
            }
            $this->loadChangelog();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function loadChangelog()
    {
        try {
            $service = app(ChangelogService::class);
            $currentVersion = str_replace('v', '', $this->currentVersion);

            $this->changelogEntries = $service->getEntries(1)
                ->filter(function ($entry) use ($currentVersion) {
                    $entryVersion = str_replace('v', '', $entry->tag_name);

                    return version_compare($entryVersion, $currentVersion, '>');
                })
                ->take(3)
                ->map(fn ($entry) => [
                    'tag_name' => $entry->tag_name,
                    'title' => $entry->title,
                    'content_html' => $entry->content_html,
                ])
                ->values()
                ->toArray();
        } catch (\Throwable $e) {
            $this->changelogEntries = [];
        }
    }

    public function upgrade()
    {
        try {
            if ($this->updateInProgress) {
                return;
            }
            $this->updateInProgress = true;
            UpdateCoolify::run(manual_update: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getUpgradeStatus(): array
    {
        // Only root team members can view upgrade status
        if (auth()->user()?->currentTeam()->id !== 0) {
            return ['status' => 'none'];
        }

        $server = Server::find(0);
        if (! $server) {
            return ['status' => 'none'];
        }

        $statusFile = '/data/coolify/source/.upgrade-status';

        try {
            $content = instant_remote_process(
                ["cat {$statusFile} 2>/dev/null || echo ''"],
                $server,
                false
            );
            $content = trim($content ?? '');
        } catch (\Throwable $e) {
            return ['status' => 'none'];
        }

        if (empty($content)) {
            return ['status' => 'none'];
        }

        $parts = explode('|', $content);
        if (count($parts) < 3) {
            return ['status' => 'none'];
        }

        [$step, $message, $timestamp] = $parts;

        // Check if status is stale (older than 10 minutes)
        try {
            $statusTime = new \DateTime($timestamp);
            $now = new \DateTime;
            $diffMinutes = ($now->getTimestamp() - $statusTime->getTimestamp()) / 60;

            if ($diffMinutes > 10) {
                return ['status' => 'none'];
            }
        } catch (\Throwable $e) {
            return ['status' => 'none'];
        }

        if ($step === 'error') {
            return [
                'status' => 'error',
                'step' => 0,
                'message' => $message,
            ];
        }

        $stepInt = (int) $step;
        $status = $stepInt >= 6 ? 'complete' : 'in_progress';

        return [
            'status' => $status,
            'step' => $stepInt,
            'message' => $message,
        ];
    }
}

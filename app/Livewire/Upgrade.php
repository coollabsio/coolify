<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
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
}

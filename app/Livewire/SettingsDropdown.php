<?php

namespace App\Livewire;

use App\Jobs\PullChangelog;
use App\Services\ChangelogService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SettingsDropdown extends Component
{
    public $showWhatsNewModal = false;

    public bool $shouldPollChangelog = false;

    public bool $changelogFetchRequested = false;

    public int $changelogPollAttempts = 0;

    public function getUnreadCountProperty()
    {
        return Auth::user()->getUnreadChangelogCount();
    }

    public function getEntriesProperty()
    {
        $user = Auth::user();

        return app(ChangelogService::class)->getEntriesForUser($user);
    }

    public function getCurrentVersionProperty()
    {
        return 'v'.config('constants.coolify.version');
    }

    public function openWhatsNewModal()
    {
        $this->showWhatsNewModal = true;

        $this->prefetchChangelog();
    }

    public function prefetchChangelog()
    {
        $entries = app(ChangelogService::class)->getEntriesForUser(Auth::user());
        if ($entries->isEmpty()) {
            if (! $this->changelogFetchRequested) {
                $this->changelogFetchRequested = true;
                $this->shouldPollChangelog = true;
                $this->changelogPollAttempts = 0;
                PullChangelog::dispatch();
            }

            return;
        }

        $this->dispatch('changelog-entries', entries: $entries->values()->all());
    }

    public function refreshChangelog()
    {
        if (! $this->shouldPollChangelog) {
            return;
        }

        $this->changelogPollAttempts++;
        if ($this->changelogPollAttempts > 10) {
            $this->shouldPollChangelog = false;
            $this->changelogFetchRequested = false;

            return;
        }

        $entries = app(ChangelogService::class)->getEntriesForUser(Auth::user());
        if ($entries->isEmpty()) {
            return;
        }

        $this->shouldPollChangelog = false;
        $this->changelogFetchRequested = false;
        $this->changelogPollAttempts = 0;
        $this->dispatch('changelog-entries', entries: $entries->values()->all());

        cache()->forget('user_unread_changelog_count_'.Auth::id());
        $this->dispatch('$refresh');
    }

    public function closeWhatsNewModal()
    {
        $this->showWhatsNewModal = false;
    }

    public function markAsRead($identifier)
    {
        app(ChangelogService::class)->markAsReadForUser($identifier, Auth::user());
    }

    public function markAllAsRead()
    {
        app(ChangelogService::class)->markAllAsReadForUser(Auth::user());
    }

    public function manualFetchChangelog()
    {
        if (! isDev()) {
            return;
        }

        try {
            PullChangelog::dispatch();
            $this->dispatch('success', 'Changelog fetch initiated! Check back in a few moments.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to fetch changelog: '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.settings-dropdown', [
            'entries' => $this->entries,
            'unreadCount' => $this->unreadCount,
            'currentVersion' => $this->currentVersion,
        ]);
    }
}

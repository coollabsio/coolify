<?php

namespace App\Livewire;

use App\Jobs\PullChangelogFromGitHub;
use App\Services\ChangelogService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SettingsDropdown extends Component
{
    public $showWhatsNewModal = false;

    public function getUnreadCountProperty()
    {
        return Auth::user()->getUnreadChangelogCount();
    }

    public function getEntriesProperty()
    {
        $user = Auth::user();

        return app(ChangelogService::class)->getEntriesForUser($user);
    }

    public function openWhatsNewModal()
    {
        $this->showWhatsNewModal = true;
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
            PullChangelogFromGitHub::dispatch();
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
        ]);
    }
}

<?php

namespace App\Livewire;

use App\Events\RecentsUpdated;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\UserRecentPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public array $pinnedPages = [];

    public function getListeners(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        return [
            "echo-private:user.{$userId},.RecentsUpdated" => 'refreshPinnedPages',
        ];
    }

    public function mount()
    {
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()->with('environments')->get();
        $this->loadPinnedPages();
    }

    public function refreshPinnedPages(): void
    {
        $this->loadPinnedPages();
    }

    public function unpinPage(string $url): void
    {
        $user = auth()->user();
        $team = $user?->currentTeam();

        if (! $team) {
            return;
        }

        // Rate limit: 10 pins per minute per user
        $key = 'toggle-pin:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return;
        }
        RateLimiter::hit($key, 60);

        UserRecentPage::togglePin($user->id, $team->id, $url);

        // Refresh local state
        $this->loadPinnedPages();

        // Broadcast update to other tabs/windows (including recents menu)
        event(new RecentsUpdated($user->id));
    }

    public function render()
    {
        return view('livewire.dashboard');
    }

    private function loadPinnedPages(): void
    {
        $user = auth()->user();
        $team = $user?->currentTeam();

        if (! $team) {
            return;
        }

        $record = UserRecentPage::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->first();

        if (! $record?->pages) {
            return;
        }

        $this->pinnedPages = collect($record->pages)
            ->filter(fn ($p) => ! empty($p['pinned']))
            ->take(5)
            ->values()
            ->all();
    }
}

<?php

namespace App\Livewire;

use App\Events\RecentsUpdated;
use App\Models\UserRecentPage;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class RecentsMenu extends Component
{
    public array $recents = [];

    public function getListeners(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        return [
            "echo-private:user.{$userId},.RecentsUpdated" => '$refresh',
        ];
    }

    public function togglePin(string $url): void
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

        // Broadcast update to other tabs/windows
        event(new RecentsUpdated($user->id));
    }

    public function render()
    {
        $this->loadRecentsData();

        return view('livewire.recents-menu');
    }

    private function loadRecentsData(): void
    {
        $user = auth()->user();
        $team = $user?->currentTeam();

        $this->recents = $team
            ? UserRecentPage::getRecent($user->id, $team->id)
            : [];
    }
}

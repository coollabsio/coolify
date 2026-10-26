<?php

namespace App\Livewire\ImageScan;

use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Team settings page for image scanning. Members see the values, admins edit them (authorized in ScanSettings).
 */
class TeamImageScanning extends Component
{
    #[Locked]
    public int $teamId;

    public function mount(): void
    {
        $team = currentTeam();
        abort_unless($team instanceof Team && auth()->user()->teams->contains('id', $team->id), 403);
        $this->teamId = $team->id;
    }

    public function render(): View
    {
        return view('livewire.image-scan.team-image-scanning', [
            'allowed' => (bool) InstanceSettings::get()->is_image_scanning_allowed,
        ]);
    }
}

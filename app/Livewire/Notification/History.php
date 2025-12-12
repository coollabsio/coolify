<?php

namespace App\Livewire\Notification;

use App\Models\NotificationHistory;
use Livewire\Component;
use Livewire\WithPagination;

class History extends Component
{
    use WithPagination;

    public string $selectedChannel = '';
    public string $selectedEventType = '';

    public function mount()
    {
        // Reset pagination when filters change
    }

    public function updatedSelectedChannel()
    {
        $this->resetPage();
    }

    public function updatedSelectedEventType()
    {
        $this->resetPage();
    }

    public function clearFilters()
    {
        $this->selectedChannel = '';
        $this->selectedEventType = '';
        $this->resetPage();
    }

    public function getFilterOptionsProperty(): array
    {
        $teamId = currentTeam()->id;

        $channels = NotificationHistory::query()
            ->where('team_id', $teamId)
            ->distinct('channel')
            ->pluck('channel')
            ->filter()
            ->sort()
            ->values()
            ->toArray();

        $eventTypes = NotificationHistory::query()
            ->where('team_id', $teamId)
            ->distinct('event_type')
            ->pluck('event_type')
            ->filter()
            ->sort()
            ->values()
            ->toArray();

        return [
            'channels' => array_combine($channels, $channels),
            'eventTypes' => array_combine($eventTypes, $eventTypes),
        ];
    }

    public function loadNotifications()
    {
        $teamId = currentTeam()->id;

        $query = NotificationHistory::query()
            ->where('team_id', $teamId)
            ->orderBy('created_at', 'desc');

        if ($this->selectedChannel) {
            $query->where('channel', $this->selectedChannel);
        }

        if ($this->selectedEventType) {
            $query->where('event_type', $this->selectedEventType);
        }

        return $query->paginate(50);
    }

    public function render()
    {
        return view('livewire.notification.history', [
            'notifications' => $this->loadNotifications(),
            'filterOptions' => $this->filterOptions,
        ]);
    }
}

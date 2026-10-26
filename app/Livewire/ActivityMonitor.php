<?php

namespace App\Livewire;

use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class ActivityMonitor extends Component
{
    public ?string $header = null;

    #[Locked]
    public $activityId = null;

    public $eventToDispatch = 'activityFinished';

    public $eventData = null;

    public $isPollingActive = false;

    public bool $fullHeight = false;

    public $activity;

    public bool $showWaiting = true;

    public static $eventDispatched = false;

    protected $listeners = [
        'activityMonitor' => 'newMonitorActivity',
        'processDialogClosed' => 'clearActivity',
    ];

    public function newMonitorActivity($activityId, $eventToDispatch = 'activityFinished', $eventData = null, $header = null)
    {
        // Reset event dispatched flag for new activity
        self::$eventDispatched = false;

        $this->activityId = $activityId;
        $this->eventToDispatch = $eventToDispatch;
        $this->eventData = $eventData;

        // Update header if provided
        if ($header !== null) {
            $this->header = $header;
        }

        $this->hydrateActivity();

        $this->isPollingActive = true;
    }

    public function clearActivity(): void
    {
        $this->activityId = null;
        $this->activity = null;
        $this->isPollingActive = false;
        $this->eventToDispatch = 'activityFinished';
        $this->eventData = null;
        self::$eventDispatched = false;
    }

    public function hydrateActivity()
    {
        if ($this->activityId === null) {
            $this->activity = null;

            return;
        }

        $activity = Activity::find($this->activityId);

        if (! $activity) {
            $this->activity = null;

            return;
        }

        $this->activity = $this->canReadActivity($activity) ? $activity : null;
    }

    /**
     * Activity output can contain secrets, so only users who can edit the resource that the
     * activity ran for (or its server) may read it. The policy uses the team of that resource,
     * not the session team.
     */
    private function canReadActivity(Activity $activity): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $subject = $activity->subject;
        if ($subject instanceof Model) {
            return $user->can('update', $subject);
        }

        // Service starts store the service uuid instead of a subject.
        $typeUuid = data_get($activity, 'properties.type_uuid');
        if (filled($typeUuid) && ($service = Service::where('uuid', $typeUuid)->first())) {
            return $user->can('update', $service);
        }

        $serverUuid = data_get($activity, 'properties.server_uuid');
        $server = filled($serverUuid) ? Server::where('uuid', $serverUuid)->first() : null;

        // Fail closed: an activity without a resource or server cannot be authorized.
        return $server !== null && $user->can('update', $server);
    }

    public function polling()
    {
        $this->hydrateActivity();
        $exit_code = data_get($this->activity, 'properties.exitCode');
        if ($exit_code !== null) {
            $this->isPollingActive = false;
            if ($exit_code === 0) {
                if ($this->eventToDispatch !== null) {
                    if (str($this->eventToDispatch)->startsWith('App\\Events\\')) {
                        $causer_id = data_get($this->activity, 'causer_id');
                        $user = User::find($causer_id);
                        if ($user) {
                            $teamId = data_get($this->activity, 'properties.team_id')
                                ?? $user->currentTeam()?->id
                                ?? $user->teams->first()?->id;
                            if ($teamId && ! self::$eventDispatched) {
                                if (filled($this->eventData)) {
                                    $this->eventToDispatch::dispatch($teamId, $this->eventData);
                                } else {
                                    $this->eventToDispatch::dispatch($teamId);
                                }
                                self::$eventDispatched = true;
                            }
                        }

                        return;
                    }
                    if (! self::$eventDispatched) {
                        if (filled($this->eventData)) {
                            $this->dispatch($this->eventToDispatch, $this->eventData);
                        } else {
                            $this->dispatch($this->eventToDispatch);
                        }
                        self::$eventDispatched = true;
                    }
                }
            }
        }
    }
}

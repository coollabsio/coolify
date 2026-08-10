<?php

namespace App\Livewire;

use App\Models\Server;
use App\Models\User;
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

    protected $listeners = ['activityMonitor' => 'newMonitorActivity'];

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

        $currentTeamId = currentTeam()?->id;

        // Check team_id stored directly in activity properties
        $activityTeamId = data_get($activity, 'properties.team_id');
        if ($activityTeamId !== null) {
            if ((int) $activityTeamId !== (int) $currentTeamId) {
                $this->activity = null;

                return;
            }

            $this->activity = $activity;

            return;
        }

        // Fallback: verify ownership via the server that ran the command
        $serverUuid = data_get($activity, 'properties.server_uuid');
        if ($serverUuid) {
            $server = Server::where('uuid', $serverUuid)->first();
            if ($server && (int) $server->team_id !== (int) $currentTeamId) {
                $this->activity = null;

                return;
            }

            if ($server) {
                $this->activity = $activity;

                return;
            }
        }

        // Fail closed: no team_id and no server_uuid means we cannot verify ownership
        $this->activity = null;
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

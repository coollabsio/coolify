<?php

namespace App\Traits;

use Illuminate\Contracts\Encryption\DecryptException;
use Livewire\Attributes\Locked;

/**
 * Keeps a component's team broadcast listeners bound to the team it was mounted for.
 *
 * Livewire rebuilds listener names on every event call. When the session team changes
 * (e.g. a team switch in another tab), names built from the live session team no longer
 * match the channel the open page listens on, and Livewire throws EventHandlerDoesNotExist.
 * Events for a team that is no longer the session team are ignored, because the handlers
 * load resources scoped to the session team.
 */
trait ListensToTeamChannel
{
    #[Locked]
    public ?int $teamChannelId = null;

    public function mountListensToTeamChannel(): void
    {
        $this->teamChannelId = currentTeam()?->id;
    }

    public function ignoreStaleTeamChannelEvent(): void
    {
        $this->skipRender();
    }

    /**
     * The data of a team channel event. Events about one resource send it encrypted in `payload`,
     * because the team channel also reaches members without access to that resource.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function teamChannelPayload(array $event): array
    {
        if (! array_key_exists('payload', $event)) {
            return $event;
        }

        try {
            $payload = decrypt($event['payload']);
        } catch (DecryptException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, string>  $handlers  broadcast event name => handler method
     * @return array<string, string>
     */
    protected function teamChannelListeners(array $handlers): array
    {
        $currentTeamId = currentTeam()?->id;
        $teamId = $this->teamChannelId ?? $currentTeamId;

        if ($teamId === null) {
            return [];
        }

        $isStale = $teamId !== $currentTeamId;

        $listeners = [];
        foreach ($handlers as $event => $handler) {
            $listeners["echo-private:team.{$teamId},{$event}"] = $isStale ? 'ignoreStaleTeamChannelEvent' : $handler;
        }

        return $listeners;
    }
}

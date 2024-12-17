<?php

namespace App\Livewire\Project\Shared;

use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Docker\GetContainersStatus;
use App\Events\ApplicationStatusChanged;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Visus\Cuid2\Cuid2;

class Destination extends Component
{
    public $resource;

    public Collection $networks;

    public function getListeners()
    {
        $teamId = auth()->user()->currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationStatusChanged" => 'loadData',
        ];
    }

    public function mount()
    {
        $this->networks = collect([]);
        $this->loadData();
    }

    public function loadData()
    {
        $all_networks = collect([]);
        $all_networks = $all_networks->push($this->resource->destination);
        $all_networks = $all_networks->merge($this->resource->additional_networks);

        $this->networks = Server::isUsable()->get()->map(function ($server) {
            return $server->standaloneDockers;
        })->flatten();
        $this->networks = $this->networks->reject(function ($network) use ($all_networks) {
            return $all_networks->pluck('id')->contains($network->id);
        });
        $this->networks = $this->networks->reject(function ($network) {
            return $this->resource->destination->server->id == $network->server->id;
        });
        if ($this->resource?->additional_servers?->count() > 0) {
            $this->networks = $this->networks->reject(function ($network) {
                return $this->resource->additional_servers->pluck('id')->contains($network->server->id);
            });
        }
    }

    public function stop($serverId)
    {
        try {
            $server = Server::ownedByCurrentTeam()->findOrFail($serverId);
            StopApplicationOneServer::run($this->resource, $server);
            $this->refreshServers();
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function redeploy(int $network_id, int $server_id)
    {
        try {
            if ($this->resource->additional_servers->count() > 0 && str($this->resource->docker_registry_image_name)->isEmpty()) {
                $this->dispatch('error', 'Failed to deploy.', 'Before deploying to multiple servers, you must first set a Docker image in the General tab.<br>More information here: <a target="_blank" class="underline" href="https://coolify.io/docs/knowledge-base/server/multiple-servers">documentation</a>');

                return;
            }
            $deployment_uuid = new Cuid2;
            $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
            $destination = $server->standaloneDockers->where('id', $network_id)->firstOrFail();
            queue_application_deployment(
                deployment_uuid: $deployment_uuid,
                application: $this->resource,
                server: $server,
                destination: $destination,
                only_this_server: true,
                no_questions_asked: true,
            );

            return redirect()->route('project.application.deployment.show', [
                'project_uuid' => data_get($this->resource, 'environment.project.uuid'),
                'application_uuid' => data_get($this->resource, 'uuid'),
                'deployment_uuid' => $deployment_uuid,
                'environment_uuid' => data_get($this->resource, 'environment.uuid'),
            ]);
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function promote(int $network_id, int $server_id)
    {
        $main_destination = $this->resource->destination;
        $this->resource->update([
            'destination_id' => $network_id,
            'destination_type' => StandaloneDocker::class,
        ]);
        $this->resource->additional_networks()->detach($network_id, ['server_id' => $server_id]);
        $this->resource->additional_networks()->attach($main_destination->id, ['server_id' => $main_destination->server->id]);
        $this->refreshServers();
    }

    public function refreshServers()
    {
        GetContainersStatus::run($this->resource->destination->server);
        // ContainerStatusJob::dispatchSync($this->resource->destination->server);
        $this->loadData();
        $this->dispatch('refresh');
        ApplicationStatusChanged::dispatch(data_get($this->resource, 'environment.project.team.id'));
    }

    public function addServer(int $network_id, int $server_id)
    {
        $this->resource->additional_networks()->attach($network_id, ['server_id' => $server_id]);
        $this->loadData();
        ApplicationStatusChanged::dispatch(data_get($this->resource, 'environment.project.team.id'));
    }

    public function removeServer(int $network_id, int $server_id, $password)
    {
        try {
            if (! data_get(InstanceSettings::get(), 'disable_two_step_confirmation')) {
                if (! Hash::check($password, Auth::user()->password)) {
                    $this->addError('password', 'The provided password is incorrect.');

                    return;
                }
            }

            if ($this->resource->destination->server->id == $server_id && $this->resource->destination->id == $network_id) {
                $this->dispatch('error', 'You cannot remove this destination server.', 'You are trying to remove the main server.');

                return;
            }
            $server = Server::ownedByCurrentTeam()->findOrFail($server_id);
            StopApplicationOneServer::run($this->resource, $server);
            $this->resource->additional_networks()->detach($network_id, ['server_id' => $server_id]);
            $this->loadData();
            ApplicationStatusChanged::dispatch(data_get($this->resource, 'environment.project.team.id'));
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }
}

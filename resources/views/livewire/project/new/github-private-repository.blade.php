<div>
    @if ($github_apps->count() > 0)
        <h1>Choose a GitHub App</h1>
        @foreach ($github_apps as $ghapp)
            <x-inputs.button wire:click="loadRepositories({{ $ghapp->id }})">{{ $ghapp->name }}</x-inputs.button>
        @endforeach
        <div>
            @if ($repositories->count() > 0)
                <h3>Choose a Repository</h3>
                <select wire:model.defer="selected_repository_id">
                    @foreach ($repositories as $repo)
                        @if ($loop->first)
                            <option selected value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'name') }}</option>
                        @else
                            <option value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'name') }}</option>
                        @endif
                    @endforeach
                </select>
                <x-inputs.button wire:click="loadBranches">Select Repository</x-inputs.button>
            @endif
        </div>
        <div>
            @if ($branches->count() > 0)
                <h3>Choose a Branch</h3>
                <select wire:model.defer="selected_branch_name">
                    <option disabled>Choose a branch</option>
                    @foreach ($branches as $branch)
                        @if ($loop->first)
                            <option selected value="{{ data_get($branch, 'name') }}">{{ data_get($branch, 'name') }}
                            </option>
                        @else
                            <option value="{{ data_get($branch, 'name') }}">{{ data_get($branch, 'name') }}</option>
                        @endif
                    @endforeach
                </select>
                <x-inputs.button wire:click="loadServers">Select Branch</x-inputs.button>
            @endif
        </div>

        <div>
            @if ($servers->count() > 0)
                <h3>Choose a Server</h3>
                <select wire:model.defer="selected_server_id">
                    <option disabled>Choose a server</option>
                    @foreach ($servers as $server)
                        @if ($loop->first)
                            <option selected value="{{ data_get($server, 'id') }}">{{ data_get($server, 'name') }}
                            </option>
                        @else
                            <option value="{{ data_get($server, 'id') }}">{{ data_get($server, 'name') }}</option>
                        @endif
                    @endforeach
                </select>
                <x-inputs.button wire:click="loadDestinations">Select Server</x-inputs.button>
            @endif
        </div>
        <div>
            @if ($destinations->count() > 0)
                <h3>Choose a Destination</h3>
                <select wire:model.defer="selected_destination_id">
                    <option disabled>Choose a destination</option>
                    @foreach ($destinations as $destination)
                        @if ($loop->first)
                            <option selected value="{{ data_get($destination, 'id') }}">
                                {{ data_get($destination, 'network') }}</option>
                        @else
                            <option value="{{ data_get($destination, 'id') }}">{{ data_get($destination, 'network') }}
                            </option>
                        @endif
                    @endforeach
                </select>
                <x-inputs.button wire:click="submit">Select Destination</x-inputs.button>
            @endif
        </div>
    @else
        Add new github app
    @endif

</div>

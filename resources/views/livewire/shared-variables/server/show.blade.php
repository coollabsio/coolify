<div>
    <x-slot:title>
        Server Variable | Coolify
    </x-slot>
    <div class="flex gap-2 items-center">
        <h1>Shared Variables for {{ data_get($server, 'name') }}</h1>
        @can('update', $server)
            <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
                <livewire:project.shared.environment-variable.add :shared="true" />
            </x-modal-input>
        @endcan
        <x-forms.button canGate="update" :canResource="$server" wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
    </div>
    <div class="flex flex-wrap gap-1 subtitle">
        <div>You can use these variables anywhere with</div>
        <div class="dark:text-warning text-coollabs">@{{ server.VARIABLENAME }} </div>
        <x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>
    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($server->environment_variables->whereNotIn('key', ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="server" />
            @empty
                <div>No environment variables found.</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            <x-forms.textarea canGate="update" :canResource="$server" rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                label="Server Shared Variables"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$server" type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
        </form>
    @endif
</div>
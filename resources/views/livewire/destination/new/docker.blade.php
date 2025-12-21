@can('createAnyResource')
    <div class="w-full ">
        <div class="subtitle">{{ __('destination.destinations_desc') }}</div>
        <form class="flex flex-col gap-4" wire:submit='submit'>
            <div class="flex gap-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="network" label="Network" required />
            </div>
            <x-forms.select id="serverId" label="{{ __('destination.select_server') }}" required wire:change="generateName">
                <option disabled>{{ __('destination.select_server') }}</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->name }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.button type="submit">
                {{ __('common.continue') }}
            </x-forms.button>
        </form>
    </div>
@else
    <x-callout type="warning" title="{{ __('destination.permission_required') }}">
        {{ __('destination.no_create_permission') }}
    </x-callout>
@endcan

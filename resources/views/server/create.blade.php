<x-layout>
    @if ($private_keys->count() === 0)
        <h1>{{ __('server.create_private_key') }}</h1>
        <div class="subtitle">{{ __('server.need_create_private_key') }}</div>
        <livewire:private-key.create from="server" />
    @else
        <livewire:server.new.by-ip :private_keys="$private_keys" :limit_reached="$limit_reached" />
    @endif
</x-layout>

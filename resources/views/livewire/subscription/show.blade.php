<div>
    <x-slot:title>
        {{ __('subscription.title') }}
    </x-slot>
    <h1>{{ __('subscription.heading_singular') }}</h1>
    <div class="subtitle">{{ __('subscription.description') }}</div>
    <livewire:subscription.actions />
</div>

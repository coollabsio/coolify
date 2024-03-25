@props([
    'status' => 'Degraded',
])
<div class="flex items-center" >
    <x-loading wire:loading.delay.longer />
    <span wire:loading.remove.delay.longer class="flex items-center">
        <div class="badge badge-warning "></div>
        <div class="pl-2 pr-1 text-xs font-bold tracking-widerr dark:text-warning">
            {{ str($status)->before(':')->headline() }}
        </div>
        @if (!str($status)->startsWith('Proxy') && !str($status)->contains('('))
            <div class="text-xs dark:text-warning">({{ str($status)->after(':') }})</div>
        @endif
    </span>
</div>

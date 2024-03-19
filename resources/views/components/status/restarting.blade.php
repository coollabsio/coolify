@props([
    'status' => 'Restarting',
])
<x-loading wire:loading.delay.longer />
<div class="flex items-center" wire:loading.remove.delay.longer>
    <div class="badge badge-warning "></div>
    <div class="pl-2 pr-1 text-xs font-bold tracking-widerr text-warning">
        {{ str($status)->before(':')->headline() }}
    </div>
    @if (!str($status)->startsWith('Proxy') && !str($status)->contains('('))
        <div class="text-xs text-warning">({{ str($status)->after(':') }})</div>
    @endif
</div>

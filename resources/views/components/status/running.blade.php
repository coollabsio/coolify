@props([
    'status' => 'Running',
])
<x-loading wire:loading.delay.longer />
<div class="flex items-center" wire:loading.remove.delay.longer>
    <div class="badge badge-success badge-xs"></div>
    <div class="pl-2 pr-1 text-xs font-bold tracking-wider text-success">
        {{ str($status)->before(':')->headline() }}
    </div>
    @if (!str($status)->startsWith('Proxy'))
        <div class="text-xs text-success">({{ str($status)->after(':') }})</div>
    @endif
</div>

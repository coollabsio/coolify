@props([
    'status' => 'Running',
])
<div class="flex items-center" >
    <x-loading wire:loading.delay.longer />
    <span wire:loading.remove.delay.longer class="flex items-center">
    <div class="badge badge-success "></div>
    <div class="pl-2 pr-1 text-xs font-bold tracking-wider text-success">
        {{ str($status)->before(':')->headline() }}
    </div>
    @if (!str($status)->startsWith('Proxy') && !str($status)->contains('('))
        <div class="text-xs {{ str($status)->contains('unhealthy') ? 'dark:text-warning' : 'text-success' }}">({{ str($status)->after(':') }})</div>
    @endif
    </span>
</div>

@props([
    'status' => 'Stopped',
])
<x-loading wire:loading.delay.longer />
<div class="flex items-center" wire:loading.remove.delay.longer>
    <div class="badge badge-error "></div>
    <div class="pl-2 pr-1 text-xs font-bold tracking-wider text-error">{{ str($status)->before(':')->headline() }}</div>
</div>

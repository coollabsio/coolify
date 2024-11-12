@props([
    'status' => 'Stopped',
    'noLoading' => false,
])
<div class="flex items-center">
    @if (!$noLoading)
        <x-loading wire:loading.delay.longer />
    @endif
    <span wire:loading.remove.delay.longer class="flex items-center">
        <div class="badge badge-error "></div>
        <div class="pl-2 pr-1 text-xs font-bold tracking-wider text-error">{{ str($status)->before(':')->headline() }}</div>
    </span>
</div>

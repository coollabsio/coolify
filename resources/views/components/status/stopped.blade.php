@props([
    'status' => 'Stopped',
])
<div class="flex items-center p-[0.125rem] bg-white border rounded dark:bg-coolgray-200 dark:border-black border-neutral-200">
    <x-loading wire:loading.delay.longer />
    <span wire:loading.remove.delay.longer class="flex items-center">
        <div class="badge badge-error "></div>
        <div class="pl-2 pr-1 text-xs font-bold tracking-wider text-error">{{ str($status)->before(':')->headline() }}</div>
    </span>
</div>

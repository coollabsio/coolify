@php
    $level = \App\Support\Metrics\PressureLevel::for($percent, $kind);
    $width = $percent === null ? 0 : min(100, round($percent, 1));
@endphp
<div class="flex items-center gap-2">
    <span class="tabular-nums {{ \App\Support\Metrics\PressureLevel::textClass($level) }}">
        {{ $percent === null ? '-' : round($percent).'%' }}
    </span>
    <div class="hidden h-1 w-16 shrink-0 overflow-hidden rounded-full bg-neutral-100 sm:block dark:bg-white/[0.06]">
        <div class="h-full rounded-full transition-[width] duration-700 ease-out {{ \App\Support\Metrics\PressureLevel::barClass($level) }}" style="width: {{ $width }}%;"></div>
    </div>
</div>

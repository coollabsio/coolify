{{--
    Status-codes summary: a single horizontal stacked bar of responses by HTTP status
    class (2xx / 3xx / 4xx / 5xx) with a legend of per-class counts, driven by the
    overview totals. Plain server-rendered markup — it updates via Livewire morph on
    range/live refresh. Hovering a segment shows a cursor-following tooltip styled to
    match the ApexCharts charts. Colors are inlined (categorical, theme-neutral) so the
    bar reads the same in light and dark. Expects `$overview` in scope.
--}}
@php
    $codes = [
        ['label' => '2xx', 'count' => (int) ($overview['s2xx'] ?? 0), 'color' => '#3b82f6'],
        ['label' => '3xx', 'count' => (int) ($overview['s3xx'] ?? 0), 'color' => '#eab308'],
        ['label' => '4xx', 'count' => (int) ($overview['s4xx'] ?? 0), 'color' => '#ec4899'],
        ['label' => '5xx', 'count' => (int) ($overview['s5xx'] ?? 0), 'color' => '#a855f7'],
    ];
    $total = array_sum(array_column($codes, 'count'));
@endphp

<div class="flex flex-col gap-3"
    x-data="{
        tip: { show: false, x: 0, y: 0, label: '', count: '', pct: '', color: '' },
        show(e, label, count, pct, color) {
            this.tip.label = label; this.tip.count = count; this.tip.pct = pct; this.tip.color = color;
            this.tip.x = e.clientX; this.tip.y = e.clientY; this.tip.show = true;
        },
        move(e) { this.tip.x = e.clientX; this.tip.y = e.clientY; },
    }">
    {{-- Legend --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
        @foreach ($codes as $code)
            <div class="flex items-center gap-1.5">
                <span class="h-2 w-2 shrink-0 rounded-full" style="background-color: {{ $code['color'] }};" aria-hidden="true"></span>
                <span class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $code['label'] }}</span>
                <span class="text-[12px] font-semibold tabular-nums text-black dark:text-fg"
                    title="{{ number_format($code['count']) }} responses">{{ compactNumber($code['count']) }}</span>
            </div>
        @endforeach
    </div>

    {{-- Stacked proportional bar --}}
    @if ($total > 0)
        <div class="flex h-7 w-full overflow-hidden rounded-md bg-neutral-100 dark:bg-white/[0.06]">
            @foreach ($codes as $code)
                @if ($code['count'] > 0)
                    @php $pct = round($code['count'] / $total * 100, 1); @endphp
                    <div class="h-full cursor-default"
                        style="width: {{ round($code['count'] / $total * 100, 3) }}%; background-color: {{ $code['color'] }}; transition: filter 0.12s ease;"
                        @mouseenter="show($event, '{{ $code['label'] }}', '{{ number_format($code['count']) }}', '{{ $pct }}%', '{{ $code['color'] }}'); $el.style.filter = 'brightness(1.12)'"
                        @mousemove="move($event)"
                        @mouseleave="tip.show = false; $el.style.filter = ''"></div>
                @endif
            @endforeach
        </div>

        {{-- Cursor-following tooltip (matches the ApexCharts tooltip look). --}}
        <div x-show="tip.show" x-cloak x-transition.opacity.duration.100ms
            :style="`left: ${tip.x + 14}px; top: ${tip.y + 14}px;`"
            class="pointer-events-none fixed z-[100] rounded-lg border border-neutral-700 bg-neutral-900 px-2.5 py-1.5 text-white shadow-lg">
            <div class="flex items-center gap-1.5 text-[12px] font-medium">
                <span class="h-2 w-2 shrink-0 rounded-full" :style="`background-color: ${tip.color};`" aria-hidden="true"></span>
                <span x-text="tip.label"></span>
            </div>
            <div class="mt-0.5 text-[11px] text-neutral-300">
                <span x-text="tip.count"></span> responses · <span x-text="tip.pct"></span>
            </div>
        </div>
    @else
        <x-empty size="sm" title="No status data" description="No responses were recorded for the selected range."
            icon-name="network" />
    @endif
</div>

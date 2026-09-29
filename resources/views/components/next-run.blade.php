@props([
    'at' => null,
    'timezone' => null,
    'enabled' => true,
    'frequency' => null,
])

@php
    // Same fallback as next_cron_run_at(): schedules without a valid server timezone run in the instance timezone.
    $displayTimezone = filled($timezone) && validate_timezone((string) $timezone) ? $timezone : config('app.timezone');
    $nextRunAt = $at ? \Illuminate\Support\Carbon::parse($at) : null;
    // Without a next run, the frequency is either not calculated yet or cannot be calculated at all.
    $isFrequencyInvalid = ! $nextRunAt && filled($frequency) && next_cron_run_at((string) $frequency, $displayTimezone, now()) === null;
@endphp

<p {{ $attributes->merge(['class' => 'text-xs leading-5 text-neutral-500 dark:text-fg-dim']) }}>
    <span class="font-medium text-neutral-700 dark:text-fg">Next run</span>
    @if (! $enabled)
        <span>Disabled</span>
    @elseif ($isFrequencyInvalid)
        <span class="text-red-500">Invalid frequency. Change it to a valid cron expression, or this schedule never runs.</span>
    @elseif (! $nextRunAt)
        <span>Calculating…</span>
    @else
        <time datetime="{{ $nextRunAt->toIso8601String() }}"
            title="{{ $nextRunAt->copy()->utc()->format('Y-m-d H:i') }} UTC">{{ $nextRunAt->copy()->setTimezone($displayTimezone)->format('Y-m-d H:i') }} ({{ $displayTimezone }})</time>
        <span>·
            {{ $nextRunAt->isFuture() ? 'in '.$nextRunAt->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) : $nextRunAt->diffForHumans() }}</span>
    @endif
</p>

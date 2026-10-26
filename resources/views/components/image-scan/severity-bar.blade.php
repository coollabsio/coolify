@props([
    'counts' => ['critical' => 0, 'high' => 0, 'medium' => 0], // fixable now only
])

@php
    $segments = [
        'critical' => ['count' => max(0, (int) ($counts['critical'] ?? 0)), 'class' => 'bg-error'],
        'high' => ['count' => max(0, (int) ($counts['high'] ?? 0)), 'class' => 'bg-severity-high'],
        'medium' => ['count' => max(0, (int) ($counts['medium'] ?? 0)), 'class' => 'bg-severity-medium'],
    ];
    $total = array_sum(array_column($segments, 'count'));
@endphp

@if ($total > 0)
    <div role="img"
        aria-label="Fixable now: {{ $segments['critical']['count'] }} critical, {{ $segments['high']['count'] }} high, {{ $segments['medium']['count'] }} medium"
        {{ $attributes->class(['flex h-1.5 w-full gap-px overflow-hidden rounded-full']) }}>
        @foreach ($segments as $segment)
            @if ($segment['count'] > 0)
                <span class="h-full min-w-px {{ $segment['class'] }}" style="flex: {{ $segment['count'] }} 1 0%"></span>
            @endif
        @endforeach
    </div>
@endif

@props([
    'severity' => 'unknown', // critical|high|medium|low|unknown
    'count' => null,
])

@php
    $severity = in_array($severity, ['critical', 'high', 'medium', 'low'], true) ? $severity : 'unknown';
    $type = $severity === 'unknown' ? 'neutral' : 'severity-'.$severity;
    $label = $count === null ? ucfirst($severity) : $count.' '.$severity;
@endphp

<x-status-badge :type="$type" :label="$label" {{ $attributes }} />

@props(['certificate' => null])

@php
    $nextAttempt = (int) ($certificate['next_attempt_at'] ?? 0);
    $retry = $nextAttempt > now()->timestamp
        ? ' Coolify tries again '.\Illuminate\Support\Carbon::createFromTimestamp($nextAttempt)->diffForHumans().'.'
        : '';
    $error = (string) ($certificate['error'] ?? '');
    [$label, $classes, $title] = match (true) {
        \App\Models\NodeWorkload::servesHttps($certificate) => [
            'HTTPS',
            'bg-success/10 text-success ring-success/20',
            'Let\'s Encrypt certificate valid until '.\Illuminate\Support\Carbon::createFromTimestamp((int) $certificate['not_after'])->toDayDateTimeString().'.',
        ],
        ($certificate['status'] ?? null) === 'dns_mismatch' => [
            'DNS',
            'bg-warning/10 text-warning ring-warning/20',
            $error.' Point the A record to an ingress server to get a certificate.'.$retry,
        ],
        ($certificate['status'] ?? null) === 'failed' => [
            'HTTPS failed',
            'bg-red-500/10 text-red-500 ring-red-500/20',
            $error.$retry,
        ],
        default => [
            'HTTPS pending',
            'bg-neutral-500/10 text-neutral-500 ring-neutral-500/20',
            'The ingress gets a certificate within a few minutes. Until then, the domain is served over HTTP.',
        ],
    };
@endphp

<span title="{{ $title }}"
    {{ $attributes->class(['shrink-0 cursor-help rounded-md px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1', $classes]) }}>{{ $label }}</span>

@props([
    'action' => 'reopenDeployment',
    'label' => 'Deploying',
    'href' => null,
    'size' => 'sm', // sm fits the heading next to the compact links; md matches the top bar buttons
])

@php
    // Persistent affordance shown while a deploy/start is running. Either re-opens
    // the in-page live-log dialog (services/databases, via $wire) or links to the
    // running deployment's log page (applications, via href) so the log is never lost.
    $deployingIndicatorClasses = 'inline-flex shrink-0 items-center gap-1.5 rounded-md font-medium ring-1 ' . ($size === 'md' ? 'h-8 px-2.5 text-[13px]' : 'px-2 py-1 text-[11px]') . ' transition-colors bg-coollabs/10 text-coollabs ring-coollabs/25 hover:bg-coollabs/15 hover:no-underline dark:bg-brand/15 dark:text-brand-light dark:ring-brand-light/25 dark:hover:bg-brand/20';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ wireNavigate() }} {{ $attributes->class($deployingIndicatorClasses) }}
        title="View the running deployment log">
        <svg @class(["animate-spin", "size-3.5" => $size === "md", "size-3" => $size !== "md"]) viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        </svg>
        <span>{{ $label }}…</span>
    </a>
@else
    <button type="button" x-on:click="$wire.{{ $action }}()" {{ $attributes->class($deployingIndicatorClasses) }}
        title="View the running deployment log">
        <svg @class(["animate-spin", "size-3.5" => $size === "md", "size-3" => $size !== "md"]) viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" opacity="0.25" />
            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
        </svg>
        <span>{{ $label }}…</span>
    </button>
@endif

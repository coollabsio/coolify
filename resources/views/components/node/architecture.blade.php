{{-- The CPU architecture of a cluster server (decision 0020) as a small pill. Nothing while it is unknown. --}}
@props(['node'])
@if (filled($architecture = $node->cpuArchitecture()))
    <span title="CPU architecture"
        {{ $attributes->merge(['class' => 'inline-flex h-[18px] shrink-0 items-center rounded-full border border-neutral-200 px-1.5 font-mono text-[10.5px] leading-none text-neutral-500 dark:border-white/[0.1] dark:text-fg-faint']) }}>{{ $architecture }}</span>
@endif

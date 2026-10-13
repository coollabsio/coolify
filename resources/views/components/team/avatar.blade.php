@props(['team'])

@if ($team->avatar_path)
    <img src="{{ team_avatar_url($team) }}" alt="{{ $team->name }}"
        {{ $attributes->class('shrink-0 rounded-md object-cover') }}>
@else
    <span {{ $attributes->class('flex shrink-0 items-center justify-center rounded-md bg-neutral-200 font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg') }}>
        {{ strtoupper(mb_substr($team->name, 0, 1)) }}
    </span>
@endif

<span class="inline-flex max-w-full min-w-0 items-baseline gap-1">
    @if ($user['link'])
        <a href="{{ $user['link'] }}" {{ wireNavigate() }} class="truncate hover:underline">{{ $user['name'] }}</a>
    @else
        <span class="truncate">{{ $user['name'] }}</span>
    @endif
    @if ($withType ?? true)
        <span class="shrink-0 text-[11px] text-neutral-400 dark:text-fg-faint">{{ $user['type'] }}</span>
    @endif
</span>

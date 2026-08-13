@props([
    'destination',
    'title' => null,
    'subtitle' => null,
])

@if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
    <x-dashboard.navbar section="destination" :parameters="['destination_uuid' => $destination->uuid]"
        :title="$title" :subtitle="$subtitle" :titleOnDesktop="true" />
@elseif (filled($title))
    <header class="mb-8 min-w-0">
        <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $title }}</h1>
        @if (filled($subtitle))
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">{{ $subtitle }}</p>
        @endif
    </header>
@endif

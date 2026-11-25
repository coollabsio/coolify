@props([
    'count' => 2,
    'label' => 'Loading',
])

{{-- Settings section skeleton: a title bar and a content block per section. --}}
<div role="status" {{ $attributes->class(['flex flex-col gap-6']) }}>
    <span class="sr-only">{{ $label }}</span>
    @for ($i = 0; $i < (int) $count; $i++)
        <section class="application-settings-section" aria-hidden="true">
            <header>
                <x-skeleton class="my-1 h-3 {{ $i % 2 === 0 ? 'w-40' : 'w-28' }} rounded-full" />
                <x-skeleton class="h-7 w-20 rounded-lg" />
            </header>
            <div class="application-settings-section-body">
                <x-skeleton class="h-40 w-full rounded-lg" />
            </div>
        </section>
    @endfor
</div>

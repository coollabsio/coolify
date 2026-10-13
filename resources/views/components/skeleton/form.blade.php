@props([
    'fields' => 4,
    'label' => 'Loading',
])

{{-- Form skeleton: a label and an input per field, two columns on wide screens. --}}
<div role="status" {{ $attributes->class(['grid gap-4 md:grid-cols-2']) }}>
    <span class="sr-only">{{ $label }}</span>
    @for ($i = 0; $i < (int) $fields; $i++)
        <div aria-hidden="true" class="flex flex-col gap-2">
            <x-skeleton class="h-2.5 {{ $i % 2 === 0 ? 'w-24' : 'w-32' }} rounded-full" />
            <x-skeleton class="h-9 w-full rounded-lg" />
        </div>
    @endfor
</div>

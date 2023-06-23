@props(['text' => null])
<span class="flex items-center gap-4 text-white">
    {{ $text }}<span
        {{ $attributes->class(['bg-warning loading', 'loading-spinner' => !$attributes->has('class')]) }}>
    </span>
</span>

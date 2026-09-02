@php
    $break = $break ?? false;
    $label = $label ?? 'Copy';
@endphp
<div class="flex min-w-0 items-center gap-1.5">
    <span @class(['min-w-0', 'break-all' => $break])>{{ $text }}</span>
    <x-copy-button :value="$text" :label="$label" />
</div>

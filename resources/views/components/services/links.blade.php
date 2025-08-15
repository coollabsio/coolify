@if ($links->count() > 0)
    <x-dropdown>
        <x-slot:title>
            Links
        </x-slot>
        @foreach ($links as $link)
            <a class="dropdown-item" target="_blank" href="{{ $link }}">
                <x-external-link class="size-4" />{{ $link }}
            </a>
        @endforeach
    </x-dropdown>
@endif

<div class="pt-4">
    @if (isset($link))
        Create a new one
        <a href="{{ $link }}" class="underline dark:text-warning">
            here.
        </a>
    {{-- @else
        Use the magic
        bar (press <span class="kbd-custom">/</span>) to create a new one. --}}
    @endif
</div>

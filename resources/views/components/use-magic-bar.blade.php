<div class="pt-4">
    @if (isset($link))
        Use the magic
        bar (press <span class="kbd-custom">/</span>) to create a new one or
        <a href="{{ $link }}"
           class="underline text-warning">
            click here
        </a>.
    @else
        Use the magic
        bar (press <span class="kbd-custom">/</span>) to create a new one.
    @endif
</div>

@isset($title, $action)
    <div tabindex="0" x-data="{ open: false }"
        class="transition border rounded cursor-pointer collapse collapse-arrow border-coolgray-200"
        :class="open ? 'collapse-open' : 'collapse-close'">
        <div class="flex flex-col justify-center text-sm select-text collapse-title" x-on:click="open = !open">
            {{ $title }}
        </div>
        <div class="collapse-content">
            {{ $action }}
        </div>
    </div>
@endisset

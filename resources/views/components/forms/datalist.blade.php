<div class="w-full">
    <label>
        @if ($label)
            {{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        @endif
        <input list={{ $id }} {{ $attributes->merge(['class' => $defaultClass]) }} @required($required)
            wire:dirty.class.remove='dark:text-white' wire:dirty.class="text-black bg-warning" wire:loading.attr="disabled"
            name={{ $id }}
            @if ($attributes->whereStartsWith('wire:model')->first()) {{ $attributes->whereStartsWith('wire:model')->first() }} @else wire:model={{ $id }} @endif
            @if ($attributes->whereStartsWith('onUpdate')->first()) wire:change={{ $attributes->whereStartsWith('onUpdate')->first() }} wire:keydown.enter={{ $attributes->whereStartsWith('onUpdate')->first() }} wire:blur={{ $attributes->whereStartsWith('onUpdate')->first() }} @else wire:change={{ $id }} wire:blur={{ $id }} wire:keydown.enter={{ $id }} @endif>
        <datalist id={{ $id }}>
            {{ $slot }}
        </datalist>
    </label>
    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
    {{-- <script>
        const input = document.querySelector(`input[list={{ $id }}]`);
        input.addEventListener('focus', function(e) {
            const input = e.target.value;
            const datalist = document.getElementById('{{ $id }}');
            if (datalist.options) {
                for (let option of datalist.options) {
                    // change background color to red on all options
                    option.style.display = "none";
                    if (option.value.includes(input)) {
                        option.style.display = "block";
                    }


                }
            }
        });
    </script> --}}
</div>

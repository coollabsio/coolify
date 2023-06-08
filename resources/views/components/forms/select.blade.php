@props([
    'id' => null,
    'label' => null,
    'helper' => $attributes->has('helper'),
    'required' => false,
])

<div {{ $attributes->merge(['class' => 'flex flex-col']) }}>
    <label class="label" for={{ $id }}>
        <span class="label-text">
            @if ($label)
                {{ $label }}
            @endif
            @if ($required)
                <span class="text-warning">*</span>
            @endif
            @if ($helper)
                <div class="group">
                    <div class="cursor-pointer text-warning">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            class="w-4 h-4 stroke-current">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div class="absolute hidden text-xs group-hover:block border-coolgray-400 bg-coolgray-500">
                        <div class="p-4 card-body">
                            {!! $helper !!}
                        </div>
                    </div>
                </div>
            @endif
        </span>
    </label>
    <select {{ $attributes }} wire:model.defer={{ $id }}>
        {{ $slot }}
    </select>

    @error($id)
        <div class="text-red-500">{{ $message }}</div>
    @enderror
</div>

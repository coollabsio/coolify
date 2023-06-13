@props([
    'id' => $attributes->has('id'),
    'type' => $attributes->get('type') ?? 'text',
    'label' => $attributes->has('label'),
    'readonly' => null,
    'required' => null,
    'disabled' => null,
    'helper' => $attributes->has('helper'),
    'noDirty' => $attributes->has('noDirty'),
])
<div {{ $attributes->merge(['class' => 'w-full form-control']) }}>
    @if ($label)
        <label class="label">
            <span class="flex gap-1 label-text">
                {{ $label }}
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
    @endif
    @if ($type === 'password')
        <div class="join" x-data>
            <input class="w-full border-r-0 rounded-l join-item" type={{ $type }}
                @if ($id) id={{ $id }} name={{ $id }} wire:model.defer={{ $id }} @endisset
        @if ($disabled !== null) disabled @endif
                @if ($required !== null) required @endif @if ($readonly !== null) readonly @endif
                @if (!$noDirty && $id) wire:dirty.class="input-warning" @endif {{ $attributes }} />
            <span x-on:click="changePasswordFieldType('{{ $id }}')" x-cloak
                class="border-l-0 border-none rounded-r hover:bg-coolgray-200 no-animation h-7 btn join-item btn-xs bg-coolgray-200 "><svg
                    xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24" stroke-width="1.5"
                    stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                    <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                    <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                </svg></span>
        </div>
    @else
        <input type={{ $type }}
            @if ($id) name={{ $id }} wire:model.defer={{ $id }} @endisset
        @if ($disabled !== null) disabled @endif
            @if ($required !== null) required @endif @if ($readonly !== null) readonly @endif
            @if (!$noDirty && $id) wire:dirty.class="input-warning" @endif {{ $attributes }} />
    @endif

    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

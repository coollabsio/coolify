@props([
    'id' => $attributes->has('id') || $attributes->has('label'),
    'type' => $attributes->get('type') ?? 'text',
    'required' => null,
    'label' => $attributes->has('label'),
    'helper' => $attributes->has('helper'),
    'noLabel' => $attributes->has('noLabel'),
    'noDirty' => $attributes->has('noDirty'),
    'disabled' => null,
])

<div {{ $attributes->merge(['class' => 'w-full form-control']) }}>
    @if (!$noLabel)
        <label class="label">
            <span class="label-text">
                @if ($label)
                    {{ $label }}
                @else
                    {{ $id }}
                @endif
                @if ($required)
                    <span class="text-warning">*</span>
                @endif
                @if ($helper)
                    <div class="-mb-1 dropdown dropdown-right">
                        <label tabindex="0" class="cursor-pointer text-warning">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                class="w-4 h-4 stroke-current">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </label>
                        <div tabindex="0"
                            class="border-2 shadow whitespace-nowrap w-max-fit border-coolgray-500 card compact dropdown-content bg-coolgray-200">
                            <div class="card-body">
                                {!! $helper !!}
                            </div>
                        </div>
                    </div>
                @endif
            </span>
        </label>
    @endif
    <input {{ $attributes }} type={{ $type }} name={{ $id }} wire:model.defer={{ $id }}
        @if ($disabled !== null) disabled @endif @if ($required !== null) required @endif
        @if (!$noDirty) wire:dirty.class="input-warning" @endif />
    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

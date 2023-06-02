@props([
    'id' => $attributes->has('id') || $attributes->has('label'),
    'required' => $attributes->has('required'),
    'label' => $attributes->has('label'),
    'helper' => $attributes->has('helper'),
    'instantSave' => $attributes->has('instantSave'),
    'noDirty' => $attributes->has('noDirty'),
])

<div class=" form-control">
    @if ($label)
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
                    <div class="dropdown dropdown-right">
                        <label tabindex="0" class="btn btn-circle btn-ghost btn-xs text-warning">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                class="w-4 h-4 stroke-current">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </label>
                        <div tabindex="0"
                            class="w-64 border-2 shadow border-coolgray-500 card compact dropdown-content bg-coolgray-200 ">
                            <div class="card-body">
                                {{ $helper }}
                            </div>
                        </div>
                    </div>
                @endif
            </span>
        </label>
    @endif
    <textarea {{ $attributes }} name={{ $id }} wire:model.defer={{ $value ?? $id }}
        @if (!$noDirty) wire:dirty.class="input-warning" @endif></textarea>
    @error($id)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

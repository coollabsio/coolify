<div class="w-full">
    @if ($label)
        <label class="label">
            <span class="flex gap-1 label-text">
                {{ $label }}
                @if ($required)
                    <span class="text-warning">*</span>
                @endif
                @if ($helper)
                    <x-helper :helper="$helper" />
                @endif
            </span>
        </label>
    @endif

    <div class="w-full">
        @if ($type === 'password')
            <div class="w-full rounded join" x-data>
                <input class="join-item" wire:model.defer={{ $id }} wire:dirty.class="input-warning"
                    @readonly($readonly) @disabled($disabled || $errors->isNotEmpty()) type={{ $type }} id={{ $id }}
                    name={{ $name }} @isset($value) value={{ $value }} @endisset
                    @isset($placeholder) placeholder={{ $placeholder }} @endisset>
                @if (!$cannotPeakPassword)
                    <span x-on:click="changePasswordFieldType" x-cloak @class([
                        'border-l-0 border-none rounded-r no-animation h-7 btn join-item btn-xs',
                        'bg-coolgray-200/50 hover:bg-coolgray-200/50 text-opacity-25' =>
                            $disabled || $readonly,
                        'bg-coolgray-200 hover:bg-coolgray-200' => !$disabled || !$readonly,
                    ])><svg
                            xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 icon" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                            <path
                                d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                        </svg>
                    </span>
                @endif
            </div>
        @else
            <input id={{ $id }} name={{ $name }} wire:model.defer={{ $id }}
                wire:dirty.class="input-warning" @readonly($readonly) @disabled($disabled || $errors->isNotEmpty())
                @isset($value) value={{ $value }} @endisset
                @isset($placeholder) placeholder={{ $placeholder }} @endisset>
        @endif
        @if (!$label && $helper)
            <x-helper :helper="$helper" />
        @endif
    </div>
</div>

<script>
    function handleKeydown(e) {
        if (e.keyCode === 9) {
            e.preventDefault();

            e.target.setRangeText(
                '  ',
                e.target.selectionStart,
                e.target.selectionStart,
                'end'
            );
        }
    }
</script>

<div class="flex-1 form-control">
    @if ($label)
        <label class="flex items-center gap-1 mb-1 text-sm font-medium">{{ $label }}
            @if ($required)
                <x-highlighted text="*" />
            @endif
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </label>
    @endif
    @if ($useMonacoEditor)
        <x-forms.monaco-editor id="{{ $modelBinding }}" language="{{ $monacoEditorLanguage }}" name="{{ $name }}"
            name="{{ $modelBinding }}" model="{{ $value ?? $modelBinding }}" wire:model="{{ $value ?? $modelBinding }}"
            readonly="{{ $readonly }}" label="dockerfile" autofocus="{{ $autofocus }}" />
    @else
        @if ($type === 'password')
            <div class="relative" x-data="{ type: 'password' }" @success.window="type = 'password'">
                @if ($allowToPeak)
                    <button type="button" x-on:click="type = type === 'password' ? 'text' : 'password'"
                        class="absolute inset-y-0 right-0 flex items-center h-6 pt-2 pr-2 cursor-pointer dark:hover:text-white"
                        aria-label="Toggle password visibility">
                        <svg x-show="type === 'password'" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                            <path
                                d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                        </svg>
                        <svg x-cloak x-show="type === 'text'" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" />
                            <path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" />
                            <path d="M3 3l18 18" />
                        </svg>
                    </button>
                @endif
                <input x-cloak x-show="type === 'password'" value="{{ $value }}"
                    {{ $attributes->merge(['class' => $defaultClassInput]) }} @required($required)
                    @if ($modelBinding !== 'null') wire:model={{ $modelBinding }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @endif
                    wire:loading.attr="disabled"
                    type="{{ $type }}" @readonly($readonly) @disabled($disabled) id="{{ $htmlId }}"
                    name="{{ $name }}" placeholder="{{ $attributes->get('placeholder') }}"
                    aria-placeholder="{{ $attributes->get('placeholder') }}">
                <textarea minlength="{{ $minlength }}" maxlength="{{ $maxlength }}" x-cloak x-show="type !== 'password'"
                    placeholder="{{ $placeholder }}" {{ $attributes->merge(['class' => $defaultClass]) }}
                    @if ($realtimeValidation) wire:model.debounce.200ms="{{ $modelBinding }}" wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]"
                @else
            wire:model={{ $value ?? $modelBinding }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @endif
                    @disabled($disabled) @readonly($readonly) @required($required) id="{{ $htmlId }}"
                    name="{{ $name }}" name={{ $modelBinding }}
                    @if ($autofocus) x-ref="autofocusInput" @endif></textarea>

            </div>
        @else
            <textarea minlength="{{ $minlength }}" maxlength="{{ $maxlength }}"
                {{ $allowTab ? '@keydown.tab=handleKeydown' : '' }} placeholder="{{ $placeholder }}"
                {{ !$spellcheck ? 'spellcheck=false' : '' }} {{ $attributes->merge(['class' => $defaultClass]) }}
                @if ($realtimeValidation) wire:model.debounce.200ms="{{ $modelBinding }}" wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]"
        @else
    wire:model={{ $value ?? $modelBinding }} wire:dirty.class="[box-shadow:inset_4px_0_0_#6b16ed,inset_0_0_0_2px_#e5e5e5] dark:[box-shadow:inset_4px_0_0_#fcd452,inset_0_0_0_2px_#242424]" @endif
                @disabled($disabled) @readonly($readonly) @required($required) id="{{ $htmlId }}"
                name="{{ $name }}" name={{ $modelBinding }}
                @if ($autofocus) x-ref="autofocusInput" @endif></textarea>
        @endif
    @endif
    @error($modelBinding)
        <label class="label">
            <span class="text-red-500 label-text-alt">{{ $message }}</span>
        </label>
    @enderror
</div>

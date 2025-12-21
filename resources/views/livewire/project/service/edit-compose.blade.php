<div x-data="{
    raw: true,
    showNormalTextarea: false,
    editorHeight: 400,
    calculateEditorHeight() {
        // Get viewport height
        const viewportHeight = window.innerHeight;
        // Modal max height is calc(100vh - 2rem) = viewport - 32px
        const modalMaxHeight = viewportHeight - 32;
        // Account for: modal header (~80px) + info text (~60px) + checkboxes (~80px) + buttons (~80px) + padding (~48px)
        const fixedElementsHeight = 348;
        // Calculate available height for editor
        const availableHeight = modalMaxHeight - fixedElementsHeight;
        // Set minimum height of 300px and maximum of available space
        this.editorHeight = Math.max(300, Math.min(availableHeight, viewportHeight - 200));
    }
}" x-init="calculateEditorHeight(); window.addEventListener('resize', () => calculateEditorHeight())">
    <div class="pb-4">{{ __('service.volume_names_hint') }}</div>

    <div class="compose-editor-container" x-bind:style="`--editor-height: ${editorHeight}px`">
        <div x-cloak x-show="raw" class="font-mono">
            <div x-cloak x-show="showNormalTextarea">
                <x-forms.textarea x-bind:style="`height: ${editorHeight}px`" id="dockerComposeRaw">
                </x-forms.textarea>
            </div>
            <div x-cloak x-show="!showNormalTextarea">
                <x-forms.textarea allowTab useMonacoEditor monacoEditorLanguage="yaml" id="dockerComposeRaw">
                </x-forms.textarea>
            </div>
        </div>
        <div x-cloak x-show="raw === false" class="font-mono">
            <x-forms.textarea x-bind:style="`height: ${editorHeight}px`" readonly id="dockerCompose">
            </x-forms.textarea>
        </div>
    </div>
    <div class="pt-2 flex gap-2">
        <div class="flex flex-col gap-2">
            <x-forms.checkbox label="{{ __('application.escape_special_chars') }}"
                helper="{{ __('application.escape_special_chars_helper') }}"
                id="isContainerLabelEscapeEnabled" instantSave></x-forms.checkbox>
            <x-forms.checkbox label="{{ __('service.show_normal_textarea') }}" x-model="showNormalTextarea"></x-forms.checkbox>
        </div>

    </div>
    <div class="flex  w-full gap-2 pt-4">
        <div x-cloak x-show="raw">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">{{ __('application.show_deployable_compose') }}</x-forms.button>
        </div>
        <div x-cloak x-show="raw === false">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">{{ __('application.show_raw_compose') }}</x-forms.button>
        </div>
        <div class="flex-1"></div>
        @if (blank($service->service_type))
            <x-forms.button class="w-28" wire:click.prevent='validateCompose'>
                {{ __('common.validate') }}
            </x-forms.button>
        @endif
        <x-forms.button class="w-28" wire:click.prevent='saveEditedCompose'>
            {{ __('common.save') }}
        </x-forms.button>
    </div>
</div>
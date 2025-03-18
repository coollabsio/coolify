<div x-data="{ raw: true, showNormalTextarea: false }">
    <div class="pb-4">Volume names are updated upon save. The service UUID will be added as a prefix to all volumes, to
        prevent
        name collision. <br>To see the actual volume names, check the Deployable Compose file, or go to Storage
        menu.</div>

    <div x-cloak x-show="raw" class="font-mono">
        <div x-cloak x-show="showNormalTextarea">
            <x-forms.textarea rows="20" id="service.docker_compose_raw">
            </x-forms.textarea>
        </div>
        <div x-cloak x-show="!showNormalTextarea">
            <x-forms.textarea allowTab useMonacoEditor monacoEditorLanguage="yaml" rows="20"
                id="service.docker_compose_raw">
            </x-forms.textarea>
        </div>
    </div>
    <div x-cloak x-show="raw === false" class="font-mono">
        <x-forms.textarea rows="20" readonly id="service.docker_compose">
        </x-forms.textarea>
    </div>
    <div class="pt-2 flex gap-2">
        <div class="flex flex-col gap-2">
            <x-forms.checkbox label="Escape special characters in labels?"
                helper="By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$.<br><br>If you want to use env variables inside the labels, turn this off."
                id="service.is_container_label_escape_enabled" instantSave></x-forms.checkbox>
            <x-forms.checkbox label="Show Normal Textarea" x-model="showNormalTextarea"></x-forms.checkbox>
        </div>

    </div>
    <div class="flex  w-full gap-2 pt-4">
        <div x-cloak x-show="raw">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Deployable Compose</x-forms.button>
        </div>
        <div x-cloak x-show="raw === false">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Source
                Compose</x-forms.button>
        </div>
        <div class="flex-1"></div>
        @if (blank($service->service_type))
            <x-forms.button class="w-28" wire:click.prevent='validateCompose'>
                Validate
            </x-forms.button>
        @endif
        <x-forms.button class="w-28" wire:click.prevent='saveEditedCompose'>
            Save
        </x-forms.button>
    </div>
</div>

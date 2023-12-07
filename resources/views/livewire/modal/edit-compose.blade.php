<form x-data="{ raw: true }" class="custom-modal" wire:submit='submit'>
    <div class="flex items-end gap-2">
        <h1>Docker Compose</h1>
        <div x-cloak x-show="raw">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Deployable Compose</x-forms.button>
        </div>
        <div x-cloak x-show="raw === false">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Source
                Compose</x-forms.button>
        </div>
    </div>
    <div>Volume names are updated upon save. The service UUID will be added as a prefix to all volumes, to prevent
        name collision. <br>To see the actual volume names, check the Deployable Compose file, or go to Storage
        menu.</div>

    <div x-cloak x-show="raw" class="font-mono">
        <x-forms.textarea rows="20" id="service.docker_compose_raw">
        </x-forms.textarea>
    </div>
    <div x-cloak x-show="raw === false" class="font-mono">
        <x-forms.textarea rows="20" readonly id="service.docker_compose">
        </x-forms.textarea>
    </div>
    <div class="flex justify-end w-full gap-2">
        <x-forms.button class="w-64" type="submit">
            Save
        </x-forms.button>
        <x-forms.button wire:click='closeModal'>
            Close
        </x-forms.button>
    </div>
</form>

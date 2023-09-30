<dialog id="composeModal" class="modal" x-data="{ raw: true }">
    <form method="dialog" class="flex flex-col gap-2 rounded max-w-7xl modal-box" wire:submit.prevent='submit'>
        <h1>Docker Compose</h1>
        <div x-cloak x-show="raw">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">Check Deployable Compose</x-forms.button>
        </div>
        <div x-cloak x-show="raw === false">
            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Source
                Compose</x-forms.button>
        </div>
        <div x-cloak x-show="raw">
            <x-forms.textarea rows="20" id="raw">
            </x-forms.textarea>
        </div>
        <div x-cloak x-show="raw === false">
            <x-forms.textarea rows="20" readonly id="actual">
            </x-forms.textarea>
        </div>
        <x-forms.button onclick="composeModal.close()" type="submit">
            Save
        </x-forms.button>
    </form>
    <form method="dialog" class="modal-backdrop">
        <button>close</button>
    </form>
</dialog>

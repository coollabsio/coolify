<form wire:submit.prevent='submit' class="flex flex-col gap-4 pb-2">
    <div class="flex gap-2">
        <div>
            <h2>Service Stack</h2>
            <div>Configuration</div>
        </div>
        <x-forms.button type="submit">Save</x-forms.button>
        <x-forms.button class="w-64" onclick="composeModal.showModal()">Edit Compose
            File</x-forms.button>
    </div>
    <div class="flex gap-2">
        <x-forms.input id="service.name" required label="Service Name"
            placeholder="My super wordpress site" />
        <x-forms.input id="service.description" label="Description" />
    </div>
</form>

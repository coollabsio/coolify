<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h3>General</h3>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required />
            <x-forms.input id="email" label="Email" readonly />
        </div>
    </form>
</div>

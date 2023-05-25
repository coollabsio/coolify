<div>
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2">
            <h3>Profile</h3>
            <x-forms.button type="submit" label="Save">Save</x-forms.button>
        </div>
        <x-forms.input id="name" label="Name" required />
        <x-forms.input id="email" label="Email" readonly />
    </form>
</div>

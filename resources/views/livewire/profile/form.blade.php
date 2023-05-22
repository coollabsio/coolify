<div>
    <form wire:submit.prevent='submit'>
        <div class="flex items-center gap-2">
            <h3>Profile</h3>
            <x-inputs.button type="submit" label="Save">Save</x-inputs.button>
        </div>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="email" label="Email" readonly />
    </form>
</div>

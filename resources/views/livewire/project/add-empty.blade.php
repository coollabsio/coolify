<form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
    <x-forms.input autofocus placeholder="Your Cool Project" id="name" label="Name" required />
    <x-forms.input placeholder="This is my cool project everyone knows about" id="description" label="Description" />
    <div class="subtitle">New project will have a default production environment.</div>
    <x-forms.button type="submit" @click="slideOverOpen=false">
        Continue
    </x-forms.button>
</form>

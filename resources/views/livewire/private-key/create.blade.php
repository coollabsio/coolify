<div>
    <form class="flex flex-col gap-2" wire:submit.prevent='createPrivateKey'>
        <div class="flex gap-2">
            <x-forms.input id="name" label="Name" required/>
            <x-forms.input id="description" label="Description"/>
        </div>
        <x-forms.textarea id="value" rows="10" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"
                          label="Private Key" required/>
        <x-forms.button type="submit">
            Save Private Key
        </x-forms.button>
    </form>
</div>

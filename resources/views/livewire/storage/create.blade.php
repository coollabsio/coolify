<div class="w-full">
    <form class="flex flex-col gap-2" wire:submit='submit'>
        <div class="flex gap-2">
            <x-forms.input required label="Name" id="name" />
            <x-forms.input label="Description" id="description" />
        </div>
        <x-forms.input required type="url" label="Endpoint" id="endpoint" />
        <div class="flex gap-2">
            <x-forms.input required label="Bucket" id="bucket" />
            <x-forms.input required label="Region" id="region" />
        </div>
        <div class="flex gap-2">
            <x-forms.input required type="password" label="Access Key" id="key" />
            <x-forms.input required type="password" label="Secret Key" id="secret" />
        </div>

        <x-forms.button type="submit">
            Validate Connection & Continue
        </x-forms.button>
    </form>
</div>

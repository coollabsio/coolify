<div class="w-full">
    <div class="mb-4">For more details, please visit the <a class="underline dark:text-warning"
            href="https://coolify.io/docs/knowledge-base/s3" target="_blank">Coolify Docs</a>.</div>
    <form class="flex flex-col gap-2" wire:submit='submit'>
        <div class="flex gap-2">
            <x-forms.input required label="Name" id="name" />
            <x-forms.input label="Description" id="description" />
        </div>
        <x-forms.input required type="url" label="Endpoint" wire:model.blur="endpoint" />
        <div class="flex gap-2">
            <x-forms.input required label="Bucket" id="bucket" />
            <x-forms.input required helper="Region only required for AWS. Leave it as-is for other providers."
                label="Region" id="region" />
        </div>
        <div class="flex gap-2">
            <x-forms.input required type="password" label="Access Key" id="key" />
            <x-forms.input required type="password" label="Secret Key" id="secret" />
        </div>

        <x-forms.button class="mt-4" type="submit">
            Validate Connection & Continue
        </x-forms.button>
    </form>
</div>

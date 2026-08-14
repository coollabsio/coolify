@can('create', App\Models\S3Storage::class)
    <div class="w-full">
        <div class="mb-4">For more details, please visit the <a class="underline dark:text-warning"
                href="https://coolify.io/docs/knowledge-base/s3/introduction" target="_blank">Coolify Docs</a>.</div>
        <form class="flex flex-col gap-2" wire:submit='submit'>
            <div class="flex gap-2">
                <x-forms.input required label="Name" id="name" />
                <x-forms.input label="Description" id="description" />
            </div>
            <x-forms.input required type="url" label="Endpoint" id="endpoint"
                x-on:blur="
                    let value = $el.value.trim();
                    const hasScheme = /^https?:/i.test(value) || /^[a-z][a-z0-9+.-]*:\/\//i.test(value);
                    if (value && !hasScheme) {
                        value = `https://${value}`;
                    }
                    if ($el.value !== value) {
                        $el.value = value;
                        $el.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                " />
            <div class="flex gap-2">
                <x-forms.input required label="Bucket" id="bucket" />
                <x-forms.input required helper="Region only required for AWS. Leave it as-is for other providers."
                    label="Region" id="region" />
            </div>
            <div class="flex gap-2">
                <x-forms.input required type="password" label="Access Key" id="key" />
                <x-forms.input required type="password" label="Secret Key" id="secret" />
            </div>

            <x-forms.button class="mt-4" type="submit" wire:target="submit">
                Validate Connection & Continue
            </x-forms.button>
        </form>
    </div>
@else
    <x-callout type="danger" title="Insufficient Permissions">
        You don't have permission to create new S3 storage configurations. Please contact your team administrator for
        access.
    </x-callout>
@endcan

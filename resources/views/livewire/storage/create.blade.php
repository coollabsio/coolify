@can('create', App\Models\S3Storage::class)
    <div class="w-full">
        <div class="mb-4">{{ __('storage.s3_docs_hint') }} <a class="underline dark:text-warning"
                href="https://coolify.io/docs/knowledge-base/s3/introduction" target="_blank">{{ __('menu.documentation') }}</a>.</div>
        <form class="flex flex-col gap-2" wire:submit='submit'>
            <div class="flex gap-2">
                <x-forms.input required label="{{ __('input.name') }}" id="name" />
                <x-forms.input label="{{ __('common.description') }}" id="description" />
            </div>
            <x-forms.input required type="url" label="{{ __('storage.endpoint') }}" wire:model.blur="endpoint" />
            <div class="flex gap-2">
                <x-forms.input required label="{{ __('storage.bucket') }}" id="bucket" />
                <x-forms.input required helper="{{ __('storage.region_helper') }}"
                    label="{{ __('storage.region') }}" id="region" />
            </div>
            <div class="flex gap-2">
                <x-forms.input required type="password" label="{{ __('storage.access_key') }}" id="key" />
                <x-forms.input required type="password" label="{{ __('storage.secret_key') }}" id="secret" />
            </div>

            <x-forms.button class="mt-4" type="submit">
                {{ __('storage.validate_connection_continue') }}
            </x-forms.button>
        </form>
    </div>
@else
    <x-callout type="warning" title="{{ __('warning.title') }}">
        {{ __('storage.no_permission_create') }}
    </x-callout>
@endcan

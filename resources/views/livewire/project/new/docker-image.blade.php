<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })">
    <h1>{{ __('application.create_new_application') }}</h1>
    <div class="pb-4">{{ __('application.docker_image_desc') }}</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pt-4 pb-1">
            <h2>{{ __('application.docker_image') }}</h2>
            <x-forms.button type="submit">{{ __('common.save') }}</x-forms.button>
        </div>
        <div class="space-y-4">
            <x-forms.input id="imageName" label="{{ __('application.image_name') }}" placeholder="nginx, docker.io/nginx:latest, ghcr.io/user/app:v1.2.3, or nginx:stable@sha256:abc123..."
                helper="{{ __('application.image_name_helper') }}"
                required autofocus />
            <div class="relative grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-forms.input id="imageTag" label="{{ __('application.tag_optional') }}" placeholder="{{ __('application.tag_placeholder') }}"
                    helper="{{ __('application.tag_helper') }}" />
                <div
                    class="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2 hidden md:flex items-center justify-center z-10">
                    <div
                        class="px-2 py-1 bg-white dark:bg-coolgray-100 border border-neutral-300 dark:border-coolgray-300 rounded text-xs font-bold text-neutral-500 dark:text-neutral-400">
                        {{ __('application.or') }}
                    </div>
                </div>
                <x-forms.input id="imageSha256" label="{{ __('application.sha256_digest_optional') }}"
                    placeholder="{{ __('application.sha256_placeholder') }}"
                    helper="{{ __('application.sha256_helper') }}" />
            </div>
        </div>
    </form>
</div>

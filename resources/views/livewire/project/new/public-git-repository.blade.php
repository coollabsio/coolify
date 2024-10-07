<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">Deploy any public Git repositories.</div>
    <form class="flex flex-col gap-2" wire:submit='loadBranch'>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col gap-2">
                <div class="flex gap-2 items-end">
                    <x-forms.input required id="repository_url" label="Repository URL (https://)"
                        helper="{!! __('repository.url') !!}" />
                    <x-forms.button type="submit">
                        Check repository
                    </x-forms.button>
                </div>
                <div>
                    For example application deployments, checkout <a class="underline dark:text-white"
                        href="https://github.com/coollabsio/coolify-examples/" target="_blank">Coolify
                        Examples</a>.
                </div>
                @if ($branchFound)
                    @if ($rate_limit_remaining && $rate_limit_reset)
                        <div class="flex gap-2 py-2">
                            <div>Rate Limit</div>
                            <x-helper
                                helper="Rate limit remaining: {{ $rate_limit_remaining }}<br>Rate limit reset at: {{ $rate_limit_reset }} UTC" />
                        </div>
                    @endif
                    <div class="flex flex-col gap-2 pb-6">
                        <div class="flex gap-2">
                            @if ($git_source === 'other')
                                <x-forms.input id="git_branch" label="Branch"
                                    helper="You can select other branches after configuration is done." />
                            @else
                                <x-forms.input disabled id="git_branch" label="Branch"
                                    helper="You can select other branches after configuration is done." />
                            @endif
                            <x-forms.select wire:model.live="build_pack" label="Build Pack" required>
                                <option value="nixpacks">Nixpacks</option>
                                <option value="static">Static</option>
                                <option value="dockerfile">Dockerfile</option>
                                <option value="dockercompose">Docker Compose</option>
                            </x-forms.select>
                            @if ($isStatic)
                                <x-forms.input id="publish_directory" label="Publish Directory"
                                    helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
                            @endif
                        </div>
                        @if ($build_pack === 'dockercompose')
                            <x-forms.input placeholder="/" wire:model.blur="base_directory" label="Base Directory"
                                helper="Directory to use as root. Useful for monorepos." />
                            <x-forms.input placeholder="/docker-compose.yaml" wire:model.blur="docker_compose_location"
                                label="Docker Compose Location"
                                helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($base_directory . $docker_compose_location, '/') }}</span>" />
                            Compose file location in your repository:<span
                                class='dark:text-warning'>{{ Str::start($base_directory . $docker_compose_location, '/') }}</span>
                        @endif
                        @if ($show_is_static)
                            <x-forms.input type="number" id="port" label="Port" :readonly="$isStatic || $build_pack === 'static'"
                                helper="The port your application listens on." />
                            <div class="w-52">
                                <x-forms.checkbox instantSave id="isStatic" label="Is it a static site?"
                                    helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                            </div>
                        @endif
                        {{-- @if ($build_pack === 'dockercompose' && isDev())
                            <div class="dark:text-warning">If you choose Docker Compose based deployments, you cannot
                                change it afterwards.</div>
                            <x-forms.checkbox instantSave label="New Compose Services (only in dev mode)"
                                id="new_compose_services"></x-forms.checkbox>
                        @endif --}}
                    </div>
                    <x-forms.button wire:click.prevent='submit'>
                        Continue
                    </x-forms.button>
                @endif
            </div>
        </div>
    </form>
</div>

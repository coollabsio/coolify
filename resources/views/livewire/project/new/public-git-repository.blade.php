<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">Deploy any public Git repositories.</div>
    <form class="flex flex-col gap-2" wire:submit.prevent>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col">
                <div class="flex items-end gap-2">
                    <x-forms.input wire:keydown.enter='load_branch' id="repository_url" label="Repository URL"
                        helper="{!! __('repository.url') !!}" />
                    <x-forms.button wire:click.prevent="load_branch">
                        Check repository
                    </x-forms.button>
                </div>
                @if ($branch_found)
                    @if ($rate_limit_remaining && $rate_limit_reset)
                        <div class="flex gap-2 py-2">
                            <div>Rate Limit</div>
                            <x-helper
                                helper="Rate limit remaining: {{ $rate_limit_remaining }}<br>Rate limit reset at: {{ $rate_limit_reset }}" />
                        </div>
                    @endif
                    <h3 class="pt-8 pb-2">Details</h3>
                    <div class="flex flex-col gap-2 pb-6">
                        <div class="flex gap-2">
                            <x-forms.input disabled id="git_branch" label="Selected branch"
                                helper="You can select other branches after configuration is done." />
                            @if ($is_static)
                                <x-forms.input id="publish_directory" label="Publish Directory"
                                    helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
                            @else
                                <x-forms.input type="number" id="port" label="Port" :readonly="$is_static"
                                    helper="The port your application listens on." />
                            @endif
                        </div>
                        <div class="w-52">
                            <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                                helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                        </div>
                    </div>
                    <x-forms.button wire:click.prevent='submit'>
                        Save New Application
                    </x-forms.button>
                @endif
            </div>
        </div>
    </form>
</div>

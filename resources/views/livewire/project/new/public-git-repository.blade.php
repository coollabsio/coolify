<div>
    <h1>New Application</h1>
    <div class="pb-4 text-sm">Deploy any public git repositories.</div>
    <form class="flex flex-col gap-2" wire:submit.prevent='submit'>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col">
                <div class="flex items-end gap-2">
                    <x-forms.input wire:keypress.enter='load_branches' id="repository_url" label="Repository URL"
                        helper="{!! __('repository.url') !!}" />
                    <x-forms.button wire:click.prevent="load_branches">
                        Check repository
                    </x-forms.button>
                </div>
                @if (count($branches) > 0)
                    <div class="flex gap-2">
                        <x-forms.select id="selected_branch" label="Branch">
                            <option value="default" disabled selected>Select a branch</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch }}">{{ $branch }}</option>
                            @endforeach
                        </x-forms.select>
                        @if ($is_static)
                            <x-forms.input class="h-8" id="publish_directory" label="Publish Directory"
                                helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
                        @else
                            <x-forms.input class="h-8" type="number" id="port" label="Port" :readonly="$is_static"
                                helper="The port your application listens on." />
                        @endif
                    </div>
                    <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                        helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                    <x-forms.button class="mt-8" type="submit">
                        Save New Application
                    </x-forms.button>
                @endif
            </div>
        </div>
    </form>
</div>

<div>
    <h1>Enter a public repository URL</h1>
    <form class="flex flex-col gap-2" wire:submit.prevent='submit'>
        <div class="flex flex-col gap-2">
            <div class="flex flex-col">
                <div class="flex items-end gap-2">
                    <x-forms.input wire:keypress.enter='load_branches' id="repository_url" label="Repository URL"
                        helper="<span class='text-helper'>Example</span>https://github.com/coollabsio/coolify-examples => main branch will be selected<br>https://github.com/coollabsio/coolify-examples/tree/nodejs-fastify => nodejs-fastify branch will be selected" />
                    <x-forms.button wire:click.prevent="load_branches">
                        Check repository
                    </x-forms.button>
                </div>
                @if (count($branches) > 0)
                    <x-forms.select id="selected_branch" label="Branch">
                        <option value="default" disabled selected>Select a branch</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch }}">{{ $branch }}</option>
                        @endforeach
                    </x-forms.select>
                @else
                    <x-forms.select id="branch" label="Branch" disabled>
                        <option value="default" selected>Set a repository first</option>
                    </x-forms.select>
                @endif
            </div>
            @if ($is_static)
                <x-forms.input id="publish_directory" label="Publish Directory"
                    helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
            @else
                <x-forms.input type="number" id="port" label="Port" :readonly="$is_static"
                    helper="The port your application listens on." />
            @endif

        </div>
        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?" />
        @if (count($branches) > 0)
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        @else
            <x-forms.button disabled type="submit">
                Save
            </x-forms.button>
        @endif

    </form>
</div>

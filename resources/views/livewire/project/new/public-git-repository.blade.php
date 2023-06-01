<div>
    <h1>Enter a public repository URL</h1>
    <form class="flex flex-col gap-2" wire:submit.prevent='submit'>
        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?" />
        <div class="flex gap-2">
            <x-forms.input id="repository_url" label="Repository URL"
                helper="<span class='text-helper'>Example</span>https://github.com/coollabsio/coolify-examples => main branch will be selected<br>https://github.com/coollabsio/coolify-examples/tree/nodejs-fastify => nodejs-fastify branch will be selected" />
            @if ($is_static)
                <x-forms.input id="publish_directory" label="Publish Directory"
                    helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
            @else
                <x-forms.input type="number" id="port" label="Port" :readonly="$is_static"
                    helper="The port your application listens on." />
            @endif
        </div>
        <x-forms.button type="submit">
            Submit
        </x-forms.button>
    </form>
</div>

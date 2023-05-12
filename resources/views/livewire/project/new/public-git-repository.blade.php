<div>
    <h1>Choose a public repository</h1>
    <form class="flex flex-col gap-2 w-96" wire:submit.prevent='submit'>
        <x-inputs.input instantSave type="checkbox" id="is_static" label="Is it a static site?" />
        <div class="flex gap-2">
            <x-inputs.input class="w-96" id="repository_url" label="Repository URL" />
            @if ($is_static)
                <x-inputs.input id="publish_directory" label="Publish Directory" />
            @else
                <x-inputs.input type="number" id="port" label="Port" :readonly="$is_static" />
            @endif
        </div>
        <x-inputs.button type="submit">
            Submit
        </x-inputs.button>
    </form>
</div>

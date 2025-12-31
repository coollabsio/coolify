<div>
    <h1>Import from coolify.json</h1>
    <div class="pb-4">Paste a coolify.json configuration to quickly create and configure an application.</div>

    <x-callout type="warning" title="Beta Feature" class="mb-4">
        This feature is in beta and may change. Configuration format and behavior are subject to change in future releases.
    </x-callout>

    <form wire:submit="submit">
        <div class="flex gap-2 pb-1">
            <h2>coolify.json</h2>
            <x-forms.button type="submit">
                <span wire:loading.remove wire:target="submit">Create Application</span>
                <span wire:loading wire:target="submit">Creating...</span>
            </x-forms.button>
        </div>

        @if ($parseError)
            <div class="pb-2 text-red-500">{{ $parseError }}</div>
        @endif

        <x-forms.textarea useMonacoEditor monacoEditorLanguage="json"
            rows="20" id="coolifyJson" autofocus wire:model.live.debounce.500ms="coolifyJson"
            placeholder='{
    "version": "1.0",
    "name": "My Application",
    "source": {
        "repository": "https://github.com/user/repo",
        "branch": "main"
    },
    "build": {
        "type": "nixpacks"
    }
}'></x-forms.textarea>

        @if ($parsedConfig)
            <div class="mt-4 p-4 bg-coolgray-100 rounded">
                <h3 class="font-bold mb-2">Configuration Preview</h3>
                <div class="grid gap-2 text-sm">
                    @if (data_get($parsedConfig, 'name'))
                        <div><span class="text-neutral-400">Name:</span> {{ data_get($parsedConfig, 'name') }}</div>
                    @endif
                    @if (data_get($parsedConfig, 'source.repository'))
                        <div><span class="text-neutral-400">Repository:</span> {{ data_get($parsedConfig, 'source.repository') }}</div>
                    @endif
                    @if (data_get($parsedConfig, 'source.branch'))
                        <div><span class="text-neutral-400">Branch:</span> {{ data_get($parsedConfig, 'source.branch') }}</div>
                    @endif
                    @if (data_get($parsedConfig, 'build.type'))
                        <div><span class="text-neutral-400">Build Type:</span> {{ data_get($parsedConfig, 'build.type') }}</div>
                    @endif
                    @if (data_get($parsedConfig, 'environment_variables'))
                        <div><span class="text-neutral-400">Environment Variables:</span>
                            {{ count(data_get($parsedConfig, 'environment_variables.production', [])) }} production,
                            {{ count(data_get($parsedConfig, 'environment_variables.preview', [])) }} preview
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </form>
</div>

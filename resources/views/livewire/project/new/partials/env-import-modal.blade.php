<x-modal-input title="Import environment variables" :wireIgnore="false"
    subtitle="The variables are added to the application when you continue.">
    <x-slot:content>
        <x-forms.button type="button">{{ $envImported ? 'Edit' : 'Review' }}</x-forms.button>
    </x-slot:content>

    <div class="flex flex-col gap-4">
        @if (count($detectedEnvFiles) > 1)
            <x-forms.listbox id="selectedEnvFile" label="Env file" live
                :options="collect($detectedEnvFiles)->map(fn ($file) => ['value' => $file, 'label' => $file])->all()" />
        @endif
        <div class="flex flex-col gap-2">
            @foreach ($envExampleVars as $key => $value)
                <div wire:key="env-import-{{ $selectedEnvFile }}-{{ $key }}"
                    class="grid grid-cols-1 items-center gap-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] sm:gap-3">
                    <span class="truncate font-mono text-[13px] text-neutral-600 dark:text-fg-dim" title="{{ $key }}">{{ $key }}</span>
                    <x-forms.input id="envExampleVars.{{ $key }}" />
                </div>
            @endforeach
        </div>
    </div>

    <x-slot:footer>
        @if ($envImported)
            <x-forms.button type="button" wire:click="clearEnvVars" @click="modalOpen = false">Remove import</x-forms.button>
        @endif
        <x-forms.button type="button" isHighlighted wire:click="confirmEnvImport" @click="modalOpen = false">
            {{ $envImported ? 'Update' : 'Import' }} {{ count($envExampleVars) }} {{ Str::plural('variable', count($envExampleVars)) }}
        </x-forms.button>
    </x-slot:footer>
</x-modal-input>

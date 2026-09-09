<div>
    <x-slot:title>
        AI Assistant | Coolify
    </x-slot>

    <div class="flex flex-col gap-4">
        <h2>AI Assistant</h2>

        <x-forms.checkbox instantSave="saveTeamToggle" id="isAiEnabled" wire:model="isAiEnabled"
            label="Enable AI assistant for this team" />

        <h3>Provider credentials</h3>
        @forelse ($credentials as $credential)
            <div class="flex items-center gap-2" wire:key="cred-{{ $credential->id }}">
                <span>{{ $credential->provider->label() }} — {{ $credential->model }}</span>
                @if ($credential->is_default)
                    <span class="badge">Default</span>
                @else
                    <x-forms.button wire:click="setDefault({{ $credential->id }})">Set default</x-forms.button>
                @endif
                <x-forms.button wire:click="testCredential({{ $credential->id }})">Test</x-forms.button>
                <x-forms.button wire:click="deleteCredential({{ $credential->id }})">Delete</x-forms.button>
            </div>
        @empty
            <p>No credentials yet. Add one below.</p>
        @endforelse

        <form wire:submit="addCredential" class="flex flex-col gap-2">
            <x-forms.select wire:model="newProvider" label="Provider">
                @foreach ($providers as $provider)
                    <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.input wire:model="newModel" label="Model" placeholder="gpt-5" />
            <x-forms.input type="password" wire:model="newApiKey" label="API key" />
            <x-forms.input wire:model="newBaseUrl" label="Base URL (Ollama / custom)" />
            <x-forms.button type="submit">Add credential</x-forms.button>
        </form>
    </div>
</div>

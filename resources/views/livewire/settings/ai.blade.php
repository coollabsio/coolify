<div>
    <x-slot:title>
        AI & MCP | Coolify
    </x-slot>

    <x-settings.layout>
        <div class="flex min-w-0 flex-col gap-6">
            @if ($isInstanceAdmin)
                <x-application.settings-section id="instance-ai-section" title="AI assistant & MCP"
                    helper="Instance-wide switches. When the AI assistant is disabled here, no team can use it regardless of their own settings.">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="is_ai_assistant_enabled" label="AI assistant"
                            helper="Enable the built-in AI assistant for this instance. Teams still bring their own provider key."
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                        <x-forms.listbox id="is_mcp_server_enabled" label="MCP server"
                            helper="Expose the authenticated Streamable HTTP endpoint at /mcp." onChange="instantSave"
                            :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                    </div>
                    @if ($is_mcp_server_enabled)
                        <x-callout type="info" title="MCP endpoint" class="mt-4">
                            <code>{{ url('/mcp') }}</code> uses Sanctum bearer tokens from Security → API Tokens.
                        </x-callout>
                    @endif
                </x-application.settings-section>
            @endif

            <x-application.settings-section id="team-ai-section" title="AI provider for this team"
                helper="Bring your own provider key. Credentials and the team toggle only affect this team.">
                <div class="flex flex-col gap-4">
                    <x-forms.listbox id="isAiEnabled" label="AI assistant for this team" onChange="saveTeamToggle"
                        :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />

                    <div class="flex flex-col gap-2">
                        <h4 class="text-sm font-medium">Provider credentials</h4>
                        @forelse ($credentials as $credential)
                            <div class="flex flex-wrap items-center gap-2 rounded-md border border-neutral-200 p-3 dark:border-white/[0.06]"
                                wire:key="cred-{{ $credential->id }}">
                                <span class="mr-auto text-sm">{{ $credential->provider->label() }} —
                                    {{ $credential->model }}</span>
                                @if ($credential->is_default)
                                    <x-status-badge status="Default" type="success" />
                                @else
                                    <x-forms.button wire:click="setDefault({{ $credential->id }})">Set
                                        default</x-forms.button>
                                @endif
                                <x-forms.button wire:click="testCredential({{ $credential->id }})">Test</x-forms.button>
                                <x-forms.button isError
                                    wire:click="deleteCredential({{ $credential->id }})">Delete</x-forms.button>
                            </div>
                        @empty
                            <x-callout type="info" title="No credentials yet">
                                Add a provider credential below to start using the AI assistant.
                            </x-callout>
                        @endforelse
                    </div>

                    <form wire:submit="addCredential" class="flex flex-col gap-4">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.select wire:model="newProvider" label="Provider">
                                @foreach ($providers as $provider)
                                    <option value="{{ $provider->value }}">{{ $provider->label() }}</option>
                                @endforeach
                            </x-forms.select>
                            <x-forms.input wire:model="newModel" label="Model" placeholder="gpt-5" />
                            <x-forms.input type="password" allowToPeak wire:model="newApiKey" label="API key" />
                            <x-forms.input wire:model="newBaseUrl" label="Base URL"
                                helper="Optional. Required for Ollama or custom OpenAI-compatible providers."
                                placeholder="http://localhost:11434/v1" />
                        </div>
                        <div>
                            <x-forms.button type="submit">Add credential</x-forms.button>
                        </div>
                    </form>
                </div>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>

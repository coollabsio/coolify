<div>
    <form wire:submit="login" class="flex flex-col gap-3">
        @if ($server === null)
            <div>
                <p class="mb-1.5 text-sm font-medium">Servers <x-highlighted text="*" /></p>
                @if ($serverOptions->isEmpty())
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">No reachable server to log in to.</p>
                @else
                    <div class="flex max-h-48 flex-col overflow-y-auto">
                        @foreach ($serverOptions as $option)
                            <x-forms.checkbox id="registry-login-server-{{ $option->uuid }}" :domValue="$option->uuid"
                                wire:model="selectedServers" wire:key="registry-login-server-{{ $option->uuid }}"
                                :label="e($option->name)" fullWidth />
                        @endforeach
                    </div>
                @endif
                @error('selectedServers')
                    <p class="mt-1 text-[12px] text-red-500">{{ $message }}</p>
                @enderror
            </div>
        @endif
        @if ($editRegistry === null)
            <x-forms.listbox id="provider" label="Provider" live portal :options="$providerOptions" />
        @endif
        @if ($preset['hint'])
            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $preset['hint'] }}</p>
        @endif
        <div class="grid gap-3 md:grid-cols-2">
            <x-forms.input id="registry" label="Registry" required :placeholder="$preset['placeholder']"
                :readonly="$editRegistry !== null || $preset['registry'] !== ''" />
            <x-forms.input id="username" label="Username" required />
        </div>
        @if ($provider === 'google')
            <x-forms.textarea id="password" label="Service account JSON key" rows="6" required monospace
                wire:key="registry-password-json" />
        @else
            <x-forms.input id="password" type="password" label="Password or token" required
                wire:key="registry-password-token" />
        @endif
        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
            Coolify does not save the token. Docker saves the login on the server in
            <code>~/.docker/config.json</code>, encoded but not encrypted.
        </p>
        <div class="flex justify-end">
            <x-forms.button type="submit" defaultClass="button button-highlighted">
                {{ $editRegistry ? 'Update login' : 'Log in' }}
            </x-forms.button>
        </div>
    </form>
</div>

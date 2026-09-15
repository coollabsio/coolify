@if ($availableS3Storages->isEmpty())
    <x-application.settings-section title="S3 storage"
        description="Send backup archives to a validated object storage destination." flush>
        <x-empty title="No validated S3 storage"
            description="Add and validate an S3 storage destination before enabling remote backups."
            icon-name="storages">
            <x-slot:contents>
                <a class="button" {{ wireNavigate() }} href="{{ route('storage.index') }}">Open S3 storage</a>
            </x-slot:contents>
        </x-empty>
    </x-application.settings-section>
@else
    <form wire:submit="submit">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="S3 storage"
            description="Choose where remote backups are stored and whether local copies are retained.">
            <x-slot:actions>
                @if (! $saveS3)
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3" isHighlighted>Enable S3</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleS3" wire:loading.attr="disabled"
                        wire:target="toggleS3">Disable S3</x-forms.button>
                @endif
            </x-slot:actions>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-forms.listbox id="s3StorageId" label="S3 storage" portal :required="$saveS3"
                    :options="$availableS3Storages->map(fn ($s3) => [
                        'value' => $s3->id,
                        'label' => $s3->name,
                    ])->values()->all()" />
                <x-forms.listbox id="disableLocalBackup" label="Local copy" portal :disabled="! $saveS3"
                    :options="[
                        ['value' => false, 'label' => 'Keep local backup'],
                        ['value' => true, 'label' => 'Delete after S3 upload'],
                    ]" />
            </div>
        </x-application.settings-section>
    </form>
@endif

@if ($backup->database_type !== 'App\Models\StandaloneClickhouse')
    @if ($availableAgeKeys->isEmpty())
        <x-application.settings-section title="Backup encryption"
            description="Encrypt backup archives with age before they leave the server." flush>
            <x-empty title="No age keys"
                description="Add an age key before enabling backup encryption. Coolify only stores the public key — decryption always happens outside Coolify."
                icon-name="keys">
                <x-slot:contents>
                    <a class="button" {{ wireNavigate() }} href="{{ route('security.age-key.index') }}">Manage age keys</a>
                </x-slot:contents>
            </x-empty>
        </x-application.settings-section>
    @else
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section title="Backup encryption"
                description="Encrypt backup archives with age before they leave the server.">
                <x-slot:actions>
                    @if (! $encryptionEnabled)
                        <x-forms.button type="button" wire:click="toggleEncryption" wire:loading.attr="disabled"
                            wire:target="toggleEncryption" isHighlighted>Enable encryption</x-forms.button>
                    @else
                        <x-forms.button type="button" wire:click="toggleEncryption" wire:loading.attr="disabled"
                            wire:target="toggleEncryption">Disable encryption</x-forms.button>
                    @endif
                </x-slot:actions>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="ageKeyId" label="Age key" portal :required="$encryptionEnabled"
                        :disabled="! $encryptionEnabled" :options="$availableAgeKeys->map(fn ($key) => [
                            'value' => $key->id,
                            'label' => $key->name,
                        ])->values()->all()" />
                </div>
                <p class="mt-1.5 text-xs text-neutral-500 dark:text-fg-dim">
                    Manage age keys from
                    <a class="text-coollabs hover:underline dark:text-warning" {{ wireNavigate() }}
                        href="{{ route('security.age-key.index') }}">Keys & Tokens</a>.
                </p>
            </x-application.settings-section>
        </form>
    @endif
@endif

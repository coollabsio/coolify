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
    <x-application.settings-section title="Backup encryption"
        description="Encrypt backup archives with age before they leave the server.">
        @if ($availableAgeKeys->isNotEmpty())
            <x-slot:actions>
                @if (! $encryptionEnabled)
                    <x-forms.button type="button" wire:click="toggleEncryption" wire:loading.attr="disabled"
                        wire:target="toggleEncryption" isHighlighted>Enable encryption</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEncryption" wire:loading.attr="disabled"
                        wire:target="toggleEncryption">Disable encryption</x-forms.button>
                @endif
            </x-slot:actions>
        @endif

        @if ($availableAgeKeys->isNotEmpty())
            <form wire:submit="submit" class="mb-5">
                <x-unsaved-bar action="submit" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="ageKeyId" label="Age key" portal :required="$encryptionEnabled"
                        :disabled="! $encryptionEnabled" :options="$availableAgeKeys->map(fn ($key) => [
                            'value' => $key->id,
                            'label' => $key->name,
                        ])->values()->all()" />
                </div>
            </form>
        @endif

        @if ($generatedAgeKeyPublicKey)
            <div class="flex flex-col gap-3 rounded-lg border border-amber-500/50 bg-amber-500/5 p-4">
                <p class="text-sm font-medium">Save this private key now — it will not be shown again</p>
                <p class="text-xs text-neutral-500 dark:text-fg-dim">
                    Coolify does not store the private key. Copy it somewhere safe (a password manager,
                    for example) before continuing — without it, backups encrypted with this key can
                    never be decrypted.
                </p>
                <div>
                    <label class="mb-1.5 block text-sm font-medium">Private key</label>
                    <textarea readonly rows="2" onclick="this.select()"
                        class="input scrollbar font-mono text-xs">{{ $generatedAgeKeyPrivateKey }}</textarea>
                </div>
                <x-forms.input id="newAgeKeyName" label="Name" placeholder="e.g. Production backups" />
                <div class="flex items-center gap-2">
                    <x-forms.button type="button" wire:click="confirmGeneratedAgeKeySaved"
                        wire:loading.attr="disabled" wire:target="confirmGeneratedAgeKeySaved"
                        isHighlighted>I've saved the private key</x-forms.button>
                    <x-forms.button type="button" wire:click="discardGeneratedAgeKey">Cancel</x-forms.button>
                </div>
            </div>
        @else
            <div class="flex flex-col gap-3" x-data="{ pasting: false }">
                <p class="text-xs text-neutral-500 dark:text-fg-dim">
                    @if ($availableAgeKeys->isEmpty())
                        Generate or add an age key before enabling backup encryption.
                    @else
                        Add another age key, or generate one to get started.
                    @endif
                    Coolify only ever stores the public key — decrypting backups happens outside Coolify
                    with the matching private key, which only you hold.
                </p>
                <div class="flex flex-wrap items-center gap-2">
                    <x-forms.button type="button" wire:click="generateAgeKey" wire:loading.attr="disabled"
                        wire:target="generateAgeKey">Generate new age key</x-forms.button>
                    <x-forms.button type="button" x-on:click="pasting = ! pasting">Add existing public
                        key</x-forms.button>
                </div>
                <form wire:submit="addExistingAgeKey" x-show="pasting" x-cloak class="flex flex-col gap-3">
                    <x-forms.input id="newAgeKeyName" label="Name" placeholder="e.g. Production backups" />
                    <x-forms.input id="newAgeKeyPublicKey" label="Public key" placeholder="age1..." />
                    <div>
                        <x-forms.button type="submit">Add key</x-forms.button>
                    </div>
                </form>
            </div>
        @endif
    </x-application.settings-section>
@endif

{{-- Host, port, and directory of an SFTP or FTPS storage (the serverParts property). --}}
<div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_8rem]">
    <div class="min-w-0">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="serverParts-host" class="mb-0! flex items-center gap-1.5 leading-4">
                Host <x-highlighted text="*" />
            </label>
        </div>
        <input id="serverParts-host" type="text" class="input" wire:model="serverParts.host"
            placeholder="backup.example.com or 192.168.1.50" autocomplete="off" required
            @disabled($disabled ?? false) />
        @error('endpoint')
            <p class="mt-1 text-[12px] text-red-500">{{ $message }}</p>
        @enderror
    </div>
    <div class="min-w-0">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="serverParts-port" class="mb-0! flex items-center gap-1.5 leading-4">Port</label>
        </div>
        <input id="serverParts-port" type="number" class="input" wire:model="serverParts.port"
            placeholder="{{ $defaultPort }}" min="1" max="65535" inputmode="numeric"
            @disabled($disabled ?? false) />
    </div>
    <div class="min-w-0 sm:col-span-2">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="serverParts-path" class="mb-0! flex items-center gap-1.5 leading-4">Directory</label>
        </div>
        <input id="serverParts-path" type="text" class="input" wire:model="serverParts.path"
            placeholder="backups/coolify" autocomplete="off" @disabled($disabled ?? false) />
        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
            Absolute path from the root of the server, for example home/user/backups. Leave it empty to use the login
            directory.
        </p>
    </div>
</div>

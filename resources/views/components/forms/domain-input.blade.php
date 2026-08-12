@props([
    'id',
    'wire' => true,
    'value' => '',
    'errorId' => null,
])

<div class="grid gap-4 sm:grid-cols-[8rem_minmax(0,1fr)_8rem]" x-data="{
    value: @if ($wire) @entangle($id) @else @js($value) @endif,
    scheme: 'https',
    host: '',
    port: '',
    path: '',
    syncing: false,
    init() {
        this.read(this.value);
        this.$watch('value', value => {
            if (!this.syncing) this.read(value);
        });
        ['scheme', 'host', 'port', 'path'].forEach(part => this.$watch(part, () => this.write()));
    },
    read(value) {
        if (!value) return;
        try {
            const url = new URL(value);
            const authority = value.match(/^[a-z][a-z0-9+.-]*:\/\/(?:\[[^\]]+\]|[^\/:?#]+)(?::(\d+))?/i);
            this.syncing = true;
            this.scheme = url.protocol.replace(':', '') === 'http' ? 'http' : 'https';
            this.host = url.hostname;
            this.port = authority?.[1] || url.port;
            this.path = `${url.pathname === '/' ? '' : url.pathname}${url.search}${url.hash}`;
            this.$nextTick(() => this.syncing = false);
        } catch (_) {}
    },
    write() {
        if (this.syncing) return;
        const path = this.path.trim();
        const normalizedPath = path && !['/', '?', '#'].includes(path[0]) ? `/${path}` : path;
        const next = `${this.scheme}://${this.host.trim()}${this.port ? `:${this.port}` : ''}${normalizedPath}`;
        if (this.value !== next) this.value = next;
    },
}" x-modelable="value" {{ $attributes->whereStartsWith('x-model') }}>
    <div class="min-w-0">
        <x-forms.listbox id="{{ $id }}-protocol" label="Protocol" :wire="false" value="https"
            x-model="scheme" portal :options="[
                ['value' => 'https', 'label' => 'https'],
                ['value' => 'http', 'label' => 'http'],
            ]" />
    </div>

    <div class="min-w-0">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}" class="mb-0! flex items-center gap-1.5 leading-4">
                Domain <x-highlighted text="*" />
            </label>
        </div>
        <input id="{{ $id }}" type="text" class="input" x-model="host" placeholder="app.example.com"
            autocomplete="off" required />
        @error($errorId ?? $id)
            <p class="mt-1 text-[12px] text-red-500">{{ $message }}</p>
        @enderror
    </div>

    <div class="min-w-0">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-port" class="mb-0! flex items-center gap-1.5 leading-4">Port</label>
        </div>
        <input id="{{ $id }}-port" type="number" class="input" x-model="port" placeholder="3000"
            min="1" max="65535" inputmode="numeric" />
    </div>

    <div class="min-w-0 sm:col-span-3">
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-path" class="mb-0! flex items-center gap-1.5 leading-4">Path</label>
        </div>
        <input id="{{ $id }}-path" type="text" class="input" x-model="path" placeholder="/api/v3"
            autocomplete="off" />
        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
            Optional path, query, or fragment appended after the domain and port.
        </p>
    </div>
</div>

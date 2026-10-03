@props([
    'base',
    'file' => null,
    'fileLabel' => null,
    'defaultFile' => null,
    'disabled' => 'false',
])

<div wire:key="repository-paths-{{ $file }}" {{ $attributes->class('grid gap-4') }} x-data="{
    base: $wire.entangle('{{ $base }}'),
    file: {{ $file ? "\$wire.entangle('{$file}')" : 'null' }},
    normalize(path) {
        path = (path ?? '').trim().replace(/\/+$/, '');
        return path === '' || path.startsWith('/') ? path : '/' + path;
    },
    get directory() {
        return this.normalize(this.base);
    },
    get location() {
        return this.normalize(this.file) || '{{ $defaultFile }}';
    },
    get repeatsBase() {
        return this.directory !== '' && this.location.startsWith(this.directory + '/');
    },
    get suggestion() {
        return this.location.slice(this.directory.length);
    },
}">
    <x-forms.input label="Base directory" placeholder="/"
        helper="Repository directory used as the build root. Useful for monorepos." x-model="base"
        x-bind:disabled="{{ $disabled }}" @blur="base = directory || '/'" />
    @if ($file)
        <x-forms.input :label="$fileLabel" :placeholder="$defaultFile" helper="Path relative to the base directory."
            x-model="file" x-bind:disabled="{{ $disabled }}" @blur="file = location" />
        <p class="col-span-full text-xs text-neutral-500 dark:text-fg-dim">
            Resolved file:
            <code class="font-mono text-coollabs dark:text-warning" x-text="directory + location"></code>
        </p>
        <x-callout class="col-span-full" x-cloak x-show="repeatsBase" title="Base directory is repeated">
            {{ $fileLabel }} is relative to the base directory, so it should not start with
            <code class="rounded bg-black/10 px-1 dark:bg-white/10" x-text="directory"></code> again.
            <button type="button" class="font-medium underline" x-show="!({{ $disabled }})"
                @click="file = suggestion">Use <span x-text="suggestion"></span></button>
        </x-callout>
    @endif
    {{ $slot }}
</div>

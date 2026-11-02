<div wire:init="loadFiles" x-on:open-file-download.window="window.location.href = $event.detail.url">
    @if (count($pendingDownloads) > 0)
        <div wire:poll.3s="checkDownloads" class="hidden"></div>
    @endif
    <x-slot:title>
        {{ data_get_str($resource, 'name')->limit(10) }} > Files | Coolify
    </x-slot>

    @if ($type === 'application')
        <livewire:project.application.heading :application="$resource" wire:key="application-heading-files" />
    @elseif ($type === 'service')
        <livewire:project.service.heading :service="$resource" :parameters="$parameters" title="Files" />
    @else
        <livewire:project.database.heading :database="$resource" />
    @endif

    {{-- Standard resource settings workspace (matches Logs/Configuration) so the
         heading spacing and sidebar behave identically to every other page. --}}
    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0"
        x-data="{
            busy: null,
            {{-- Show a label for the running action over the listing. The short
                 delay skips the overlay for fast requests. --}}
            run(label, action) {
                const timer = setTimeout(() => this.busy = label, 150);
                {{-- Start the action now: callers clear their inputs right after run(). --}}
                return new Promise((resolve) => resolve(action())).catch(() => {}).finally(() => {
                    clearTimeout(timer);
                    this.busy = null;
                });
            },
            uploadFile(file) {
                return this.run('Uploading file...', () => new Promise((resolve, reject) => {
                    this.$wire.upload('upload', file, () => this.$wire.uploadFile().then(resolve, reject), reject);
                }));
            },
        }">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            @if ($type === 'application')
                <x-application.configuration-sidebar :application="$resource"
                    current-route="project.application.files" />
            @elseif ($type === 'service')
                <x-service.configuration-sidebar :service="$resource"
                    current-route="project.service.files" />
            @else
                <x-database.configuration-sidebar :database="$resource"
                    current-route="project.database.files" />
            @endif

            <div class="min-w-0">
    <x-application.settings-section title="Files"
        helper="Browse and manage files inside the running container." :flush="true">

        <x-slot:badges>
            <x-beta-badge />
            @if ($containerRunning && $filesLoaded && count($availableContainers) > 1)
                <div class="-my-0.5 w-full sm:w-72" wire:key="file-browser-container-{{ $container }}"
                    x-data="{ selected: @js($container) }"
                    x-init="$watch('selected', (name) => run('Loading files...', () => $wire.selectContainer(name)))">
                    <x-forms.listbox id="file-browser-container" :wire="false" x-model="selected" searchable portal
                        searchPlaceholder="Search containers"
                        :options="collect($availableContainers)->map(fn ($name) => ['value' => $name, 'label' => $name])->all()" />
                </div>
            @endif
        </x-slot:badges>

        <x-slot:actions>
            @if ($containerRunning && $filesLoaded && ! $shellUnavailable)
                <input type="file" class="hidden" x-ref="uploadInput"
                    @change="if ($event.target.files.length) { uploadFile($event.target.files[0]); $event.target.value = '' }" />
                <x-forms.button type="button" title="Upload a file to this folder" canGate="canAccessTerminal" :canResource="$resource"
                    wire:target="uploadFile" @click="$refs.uploadInput.click()">
                    Upload
                </x-forms.button>

                <div x-data="{ creationMenuOpen: false }" @keydown.escape.window="creationMenuOpen = false">
                    <x-forms.button type="button" x-ref="newTrigger"
                        @click="creationMenuOpen = !creationMenuOpen" x-bind:aria-expanded="creationMenuOpen"
                        canGate="canAccessTerminal" :canResource="$resource">
                        New
                        <x-reicon name="chevron-down" class="size-3.5" />
                    </x-forms.button>
                    <template x-teleport="body">
                        <div x-show="creationMenuOpen" x-cloak x-transition.opacity.duration.120ms
                            x-anchor.bottom-end.fixed.offset.4="$refs.newTrigger"
                            @click.outside="creationMenuOpen = false"
                            class="listbox-panel min-w-36! w-36! z-50!">
                            <x-modal-input title="New file">
                                <x-slot:content>
                                    <button type="button" class="listbox-option" @click="creationMenuOpen = false">
                                        <x-reicon name="file" class="size-3.5 shrink-0" />
                                        <span class="flex-1 text-left">New file</span>
                                    </button>
                                </x-slot:content>
                                <form x-data="{ name: '' }"
                                    @submit.prevent="run('Creating file...', () => $wire.createFile(name)); name=''; $dispatch('close-modal')"
                                    class="flex flex-col gap-4">
                                    <x-forms.input id="newFileName" label="File name" x-model="name"
                                        placeholder="config.yml" required />
                                    <div class="flex justify-end">
                                        <x-forms.button type="submit" isHighlighted canGate="canAccessTerminal" :canResource="$resource">Create file</x-forms.button>
                                    </div>
                                </form>
                            </x-modal-input>

                            <x-modal-input title="New folder">
                                <x-slot:content>
                                    <button type="button" class="listbox-option" @click="creationMenuOpen = false">
                                        <x-reicon name="folder" class="size-3.5 shrink-0" />
                                        <span class="flex-1 text-left">New folder</span>
                                    </button>
                                </x-slot:content>
                                <form x-data="{ name: '' }"
                                    @submit.prevent="run('Creating folder...', () => $wire.createDirectory(name)); name=''; $dispatch('close-modal')"
                                    class="flex flex-col gap-4">
                                    <x-forms.input id="newFolderName" label="Folder name" x-model="name"
                                        placeholder="plugins" required />
                                    <div class="flex justify-end">
                                        <x-forms.button type="submit" isHighlighted canGate="canAccessTerminal" :canResource="$resource">Create folder</x-forms.button>
                                    </div>
                                </form>
                            </x-modal-input>
                        </div>
                    </template>
                </div>

                <x-forms.button type="button" @click="run('Loading files...', () => $wire.refresh())" title="Refresh this folder">
                    Refresh
                </x-forms.button>
            @elseif (! $filesLoaded && ! $loadFailed)
                {{-- Disabled placeholders keep the header at its loaded height. --}}
                <x-forms.button type="button" disabled>Upload</x-forms.button>
                <x-forms.button type="button" disabled>
                    New
                    <x-reicon name="chevron-down" class="size-3.5" />
                </x-forms.button>
                <x-forms.button type="button" disabled>Refresh</x-forms.button>
            @endif
        </x-slot:actions>

        @if ($loadFailed)
            <x-empty title="Could not load files" description="Check the server connection and try again." />
            <div class="flex justify-center pb-4">
                <x-forms.button wire:click="loadFiles">Retry</x-forms.button>
            </div>
        @elseif (! $filesLoaded)
            <div class="flex justify-center py-12">
                <x-loading text="Loading files..." />
            </div>
        @elseif (! $containerRunning)
            <x-empty title="Container is not running"
                description="Start the container to browse its files.">
                <x-slot:icon>
                    <x-reicon name="folder" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @elseif ($shellUnavailable)
            <x-empty title="File browser is not available"
                description="Coolify cannot open the files of this container. {{ $helperError }}">
                <x-slot:icon>
                    <x-reicon name="folder" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @else
            {{-- Keep the current listing visible while an action runs: blur it
                 and show the action label (from run()) on top of it. --}}
            <div class="relative">
            <div x-show="busy" x-cloak class="absolute inset-0 z-10">
                <div class="flex h-full items-start justify-center">
                    <div class="sticky top-[40vh] mt-12 inline-flex items-center gap-2 rounded-md bg-white px-3 py-1.5 text-[13px] text-neutral-500 shadow-sm ring-1 ring-black/5 dark:bg-coolgray-100 dark:text-fg-dim dark:ring-white/10">
                        <x-loading />
                        <span x-text="busy"></span>
                    </div>
                </div>
            </div>
            <div x-bind:class="busy && 'pointer-events-none select-none blur-[2px] opacity-60'"
                class="transition-[filter,opacity] duration-150 motion-reduce:transition-none">
            {{-- Breadcrumb. The root crumb renders as "/" and doubles as the
                 first separator, so we only draw a slash between named segments
                 (index >= 2) - avoids the "/ /" duplicate. --}}
            <div class="flex flex-wrap items-center px-4 py-2.5 text-sm">
                @foreach ($this->breadcrumbs() as $crumb)
                    @if ($loop->index > 1)
                        <span class="text-coollabs-subtle">/</span>
                    @endif
                    <button type="button" x-on:click="run('Loading files...', () => $wire.goTo(@js($crumb['path'])))"
                        @class([
                            'text-current font-medium cursor-default' => $loop->last,
                            'cursor-pointer text-coollabs-subtle underline-offset-4 hover:text-current hover:underline' => ! $loop->last,
                        ])>
                        {{ $crumb['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- Drag-and-drop file list --}}
            <div x-data="{ dragging: false }"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="dragging = false; if ($event.dataTransfer.files.length) { uploadFile($event.dataTransfer.files[0]) }"
                :class="dragging ? 'ring-2 ring-coollabs/40 dark:ring-white/30' : ''"
                class="transition-[box-shadow] duration-150 ease-out motion-reduce:transition-none">
                @if (count($entries) === 0)
                    <x-empty size="sm" title="This folder is empty"
                        description="Upload a file to get started.">
                        <x-slot:icon>
                            <x-reicon name="folder" class="size-8" />
                        </x-slot:icon>
                    </x-empty>
                @else
                    <div class="data-table w-full">
                        <div class="data-table-header file-table-grid">
                            <span></span>
                            <span>Name</span>
                            <span>Perms</span>
                            <span>Owner</span>
                            <span>Group</span>
                            <span class="text-right">Size</span>
                            <span>Modified</span>
                            <span class="text-right">Actions</span>
                        </div>
                        @foreach ($entries as $entry)
                            <div wire:key="entry-{{ $entry['name'] }}" class="data-table-row file-table-grid">
                                <div class="flex items-center">
                                    <x-reicon :name="$entry['type'] === 'dir' ? 'folder-filled' : 'file'"
                                        class="size-4 {{ $entry['type'] === 'dir' ? 'text-coollabs dark:text-neutral-200' : 'text-coollabs-subtle opacity-70' }}" />
                                </div>
                                <div class="min-w-0">
                                    @if ($entry['type'] === 'dir')
                                        <button type="button" x-on:click="run('Loading files...', () => $wire.open(@js($entry['name'])))"
                                            class="block w-full cursor-pointer truncate text-left font-medium hover:text-coollabs hover:underline dark:hover:text-white">{{ $entry['name'] }}</button>
                                    @else
                                        <span class="block truncate">{{ $entry['name'] }}</span>
                                    @endif
                                </div>
                                {{-- Permissions (click to chmod) --}}
                                <div class="min-w-0">
                                    <x-modal-input title="Change permissions">
                                        <x-slot:content>
                                            <button type="button" title="Edit permissions"
                                                class="cursor-pointer rounded-md px-1.5 py-0.5 font-mono text-xs text-coollabs-subtle hover:bg-black/5 hover:text-current dark:hover:bg-white/[0.06]">
                                                {{ $entry['perms'] ?: '-' }}
                                            </button>
                                        </x-slot:content>
                                        <form x-data="{ mode: @js($entry['perms']) }"
                                            @submit.prevent="run('Updating permissions...', () => $wire.chmodEntry(@js($entry['name']), mode)); $dispatch('close-modal')"
                                            class="flex flex-col gap-4">
                                            <p class="text-sm text-coollabs-subtle">
                                                Set the octal permission mode for
                                                <span class="font-medium">{{ $entry['name'] }}</span>.
                                            </p>
                                            <x-forms.input id="chmodMode-{{ $loop->index }}" label="Octal mode"
                                                x-model="mode" placeholder="644" required />
                                            <div class="flex justify-end">
                                                <x-forms.button type="submit" isHighlighted canGate="canAccessTerminal" :canResource="$resource">Apply</x-forms.button>
                                            </div>
                                        </form>
                                    </x-modal-input>
                                </div>
                                <div class="min-w-0 truncate text-coollabs-subtle">
                                    {{ $entry['owner'] ?: '-' }}
                                </div>
                                <div class="min-w-0 truncate text-coollabs-subtle">
                                    {{ $entry['group'] ?: '-' }}
                                </div>
                                <div class="text-right text-coollabs-subtle tabular-nums">
                                    {{ $entry['type'] === 'dir' ? '-' : formatBytes($entry['size']) }}
                                </div>
                                <div class="text-coollabs-subtle tabular-nums">
                                    {{ $entry['mtime'] ? \Illuminate\Support\Carbon::createFromTimestamp($entry['mtime'])->toDateTimeString() : '-' }}
                                </div>
                                {{-- Actions kebab. The entry name lives in this
                                     row's x-data as a JS string so the menu can
                                     call $wire.* with it directly - passing @js()
                                     through a component attribute double-encodes
                                     the quotes and breaks the handler. --}}
                                <div class="flex items-center justify-end"
                                    x-data="{ open: false, name: @js($entry['name']), isDir: @js($entry['type'] === 'dir') }"
                                    @keydown.escape.window="open = false">
                                    <div class="relative">
                                        <button type="button" title="Actions" aria-label="Actions" x-ref="trigger"
                                            x-on:click="open = !open" x-bind:aria-expanded="open"
                                            x-bind:class="open && 'bg-black/5 text-current dark:bg-white/[0.08]'"
                                            class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-coollabs-subtle hover:bg-black/5 hover:text-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs/40 dark:hover:bg-white/[0.06] dark:focus-visible:ring-white/30">
                                            <x-reicon name="dots-horizontal" class="size-4" />
                                        </button>
                                        <template x-teleport="body">
                                            <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                                                x-anchor.bottom-end.fixed.offset.4="$refs.trigger"
                                                @click.outside="open = false"
                                                class="listbox-panel min-w-52! w-52! z-50!">
                                                @if ($entry['type'] !== 'dir')
                                                    <button type="button" class="listbox-option"
                                                        x-on:click="open = false; run('Opening file...', () => $wire.openEditor(name))">
                                                        <x-reicon name="file-content" class="size-3.5 shrink-0" />
                                                        <span class="min-w-0 flex-1 text-left">Edit</span>
                                                    </button>
                                                @endif
                                                <button type="button" class="listbox-option"
                                                    x-on:click="open = false; run(isDir ? 'Preparing folder download...' : 'Preparing file download...', () => $wire.download(name))">
                                                    <x-reicon name="download" class="size-3.5 shrink-0" />
                                                    <span class="min-w-0 flex-1 text-left">Download</span>
                                                </button>

                                                {{-- Rename --}}
                                                <x-modal-input title="Rename">
                                                    <x-slot:content>
                                                        <button type="button" class="listbox-option" x-on:click="open = false">
                                                            <x-reicon name="edit" class="size-3.5 shrink-0" />
                                                            <span class="min-w-0 flex-1 text-left">Rename</span>
                                                        </button>
                                                    </x-slot:content>
                                                    <form x-data="{ newName: @js($entry['name']) }"
                                                        @submit.prevent="run('Renaming...', () => $wire.renameEntry(name, newName)); $dispatch('close-modal')"
                                                        class="flex flex-col gap-4">
                                                        <x-forms.input id="renameName-{{ $loop->index }}"
                                                            label="New name" x-model="newName" required />
                                                        <div class="flex justify-end">
                                                            <x-forms.button type="submit" isHighlighted canGate="canAccessTerminal" :canResource="$resource">Rename</x-forms.button>
                                                        </div>
                                                    </form>
                                                </x-modal-input>

                                                {{-- Delete --}}
                                                <x-modal-input title="Delete this item?">
                                                    <x-slot:content>
                                                        <button type="button" class="listbox-option" x-on:click="open = false">
                                                            <x-reicon name="trash" class="size-3.5 shrink-0 text-red-600 dark:text-red-500" />
                                                            <span class="min-w-0 flex-1 text-left text-red-600 dark:text-red-500">Delete</span>
                                                        </button>
                                                    </x-slot:content>
                                                    <div class="flex flex-col gap-4">
                                                        <p class="text-sm text-coollabs-subtle">
                                                            This permanently deletes
                                                            <span class="font-medium">{{ $entry['name'] }}</span>
                                                            inside the container. This cannot be undone.
                                                        </p>
                                                        <div class="flex justify-end">
                                                            <x-forms.button isError type="button" canGate="canAccessTerminal" :canResource="$resource"
                                                                x-on:click="run(isDir ? 'Deleting folder...' : 'Deleting file...', () => $wire.deleteEntry(name)); $dispatch('close-modal')">Delete</x-forms.button>
                                                        </div>
                                                    </div>
                                                </x-modal-input>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            </div>
            </div>

            {{-- Editor modal (opens when a text file is loaded) --}}
            <x-modal-input title="Edit file" :wireOpen="'editorOpen'" :content="true" wireIgnore="false" isLarge>
                <x-slot:content>
                    <span class="hidden"></span>
                </x-slot:content>
                <form @submit.prevent="run('Saving file...', () => $wire.saveEditor())" class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate font-mono text-xs text-coollabs-subtle">{{ $editingPath }}</p>
                        @if ($editorLanguage)
                            <span class="shrink-0 rounded-md bg-coollabs/10 px-1.5 py-0.5 font-mono text-[11px] text-coollabs dark:bg-white/[0.08] dark:text-neutral-200">{{ $editorLanguage }}</span>
                        @endif
                    </div>
                    {{-- Monaco editor, kept mounted across opens (wire:ignore) and
                         preloaded on page init so the first Edit click is instant.
                         openEditor dispatches load-file-editor with the content and
                         Monaco language id; we setValue + setModelLanguage without
                         recreating, so highlighting applies and there is no flash.
                         Inline Alpine (like x-forms.monaco-editor) means this works
                         without a JS rebuild. --}}
                    <div wire:ignore class="min-w-0" x-init="loadMonaco()"
                        x-on:load-file-editor.window="load($event.detail)"
                        x-data="{
                            vs: '/js/monaco-editor-0.52.2/min/vs',
                            base: '/js/monaco-editor-0.52.2/min',
                            isDark() {
                                return document.documentElement.classList.contains('dark') || localStorage.theme === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
                            },
                            loadMonaco() {
                                if (window.__fileEditorMonaco) return window.__fileEditorMonaco;
                                const vs = this.vs, base = this.base;
                                window.__fileEditorMonaco = new Promise((resolve, reject) => {
                                    const boot = () => {
                                        window.require.config({ paths: { vs } });
                                        const proxy = URL.createObjectURL(new Blob([`self.MonacoEnvironment={baseUrl:'${window.location.origin}${base}'};importScripts('${window.location.origin}${vs}/base/worker/workerMain.js');`], { type: 'text/javascript' }));
                                        window.MonacoEnvironment = { getWorkerUrl: () => proxy };
                                        window.require(['vs/editor/editor.main'], () => resolve(window.monaco));
                                    };
                                    if (typeof window.require !== 'undefined' && window.require.config) { boot(); return; }
                                    const s = document.createElement('script');
                                    s.src = `${vs}/loader.js`; s.onload = boot; s.onerror = reject;
                                    document.head.appendChild(s);
                                });
                                return window.__fileEditorMonaco;
                            },
                            ensureTheme(monaco) {
                                if (window.__fileEditorThemeDefined) return;
                                monaco.editor.defineTheme('coolify-dark', { base: 'vs-dark', inherit: true, rules: [], colors: { 'editor.background': '#0b0b0c', 'editorGutter.background': '#0b0b0c', 'minimap.background': '#0b0b0c' } });
                                window.__fileEditorThemeDefined = true;
                            },
                            async load(detail) {
                                const monaco = await this.loadMonaco();
                                this.ensureTheme(monaco);
                                const el = this.$refs.editor;
                                const wire = this.$wire;
                                const content = (detail && detail.content) || '';
                                const language = (detail && detail.language) || 'plaintext';
                                // Keep the Monaco instance on the DOM node, NOT in
                                // Alpine reactive state - proxying its huge object
                                // graph would hang and crash the tab.
                                if (!el._monacoEditor) {
                                    const editor = monaco.editor.create(el, { value: content, language, theme: this.isDark() ? 'coolify-dark' : 'vs', automaticLayout: true, minimap: { enabled: false }, fontSize: 14, lineNumbersMinChars: 3, scrollBeyondLastLine: false, renderLineHighlight: 'all', padding: { top: 12, bottom: 12 }, scrollbar: { verticalScrollbarSize: 8, horizontalScrollbarSize: 8, useShadows: false } });
                                    el._monacoEditor = editor;
                                    editor.onDidChangeModelContent(() => { wire.set('editorContent', editor.getValue(), false); });
                                    new MutationObserver(() => monaco.editor.setTheme(this.isDark() ? 'coolify-dark' : 'vs')).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
                                } else {
                                    const model = el._monacoEditor.getModel();
                                    model.setValue(content);
                                    monaco.editor.setModelLanguage(model, language);
                                }
                                const editor = el._monacoEditor;
                                this.$nextTick(() => { editor.layout(); setTimeout(() => editor.focus(), 40); });
                            }
                        }">
                        <div x-ref="editor" class="file-editor-monaco"></div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-forms.button type="button" wire:click="closeEditor"
                            @click="$dispatch('close-modal')">Cancel</x-forms.button>
                        <x-forms.button type="submit" isHighlighted
                            @click="$dispatch('close-modal')">Save changes</x-forms.button>
                    </div>
                </form>
            </x-modal-input>
        @endif
    </x-application.settings-section>
            </div>
        </div>
    </section>
</div>

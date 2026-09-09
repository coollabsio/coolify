<div>
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
    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0" x-data="{ navOpen: true }"
        x-init="navOpen = (localStorage.getItem('coolify.filesNav') ?? 'open') !== 'closed';
                $watch('navOpen', v => localStorage.setItem('coolify.filesNav', v ? 'open' : 'closed'))">
        <div class="files-grid grid min-w-0 gap-8" :data-nav="navOpen ? 'open' : 'closed'">
            {{-- Resource navigation, shown by default. The header toggle collapses
                 this column smoothly on desktop; below xl it stacks like other
                 resource pages. --}}
            <div class="files-nav min-w-0">
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
            </div>

            <div class="min-w-0">
    <x-application.settings-section title="Files"
        helper="Browse and manage files inside the running container." :flush="true">

        <x-slot:actions>
            {{-- Toggle resource navigation (desktop) --}}
            <x-forms.button type="button" @click="navOpen = !navOpen"
                x-bind:title="navOpen ? 'Hide navigation' : 'Show navigation'"
                x-bind:class="navOpen && 'bg-coollabs/10 text-coollabs ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'"
                class="hidden xl:inline-flex">
                <x-reicon name="unordered-list" class="size-3.5" />
            </x-forms.button>

            @if ($containerRunning)
                <input type="file" class="hidden" x-ref="uploadInput" wire:model="upload"
                    @change="$nextTick(() => $wire.uploadFile())" />
                <x-forms.button type="button" title="Upload a file to this folder"
                    wire:target="uploadFile" @click="$refs.uploadInput.click()">
                    Upload
                </x-forms.button>

                <x-modal-input buttonTitle="New file" title="New file">
                    <form x-data="{ name: '' }"
                        @submit.prevent="$wire.createFile(name); name=''; $dispatch('close-modal')"
                        class="flex flex-col gap-4">
                        <x-forms.input id="newFileName" label="File name" x-model="name"
                            placeholder="config.yml" required />
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Create file</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>

                <x-modal-input buttonTitle="New folder" title="New folder">
                    <form x-data="{ name: '' }"
                        @submit.prevent="$wire.createDirectory(name); name=''; $dispatch('close-modal')"
                        class="flex flex-col gap-4">
                        <x-forms.input id="newFolderName" label="Folder name" x-model="name"
                            placeholder="plugins" required />
                        <div class="flex justify-end">
                            <x-forms.button type="submit" isHighlighted>Create folder</x-forms.button>
                        </div>
                    </form>
                </x-modal-input>

                <x-forms.button type="button" wire:click="refresh" title="Refresh this folder">
                    Refresh
                </x-forms.button>
            @endif
        </x-slot:actions>

        @if (! $containerRunning)
            <x-empty title="Container is not running"
                description="Start the container to browse its files.">
                <x-slot:icon>
                    <x-reicon name="folder" class="size-8" />
                </x-slot:icon>
            </x-empty>
        @else
            {{-- Container picker (services / multi-container) --}}
            @if (count($availableContainers) > 1)
                <div class="flex flex-wrap items-center gap-2 border-b border-coollabs-hairline px-4 py-2">
                    <span class="text-xs text-coollabs-subtle">Container</span>
                    @foreach ($availableContainers as $name)
                        <button type="button" x-on:click="$wire.selectContainer(@js($name))"
                            @class([
                                'button',
                                'bg-coollabs/10 text-coollabs ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $name === $container,
                            ])>{{ $name }}</button>
                    @endforeach
                </div>
            @endif

            {{-- Breadcrumb. The root crumb renders as "/" and doubles as the
                 first separator, so we only draw a slash between named segments
                 (index >= 2) - avoids the "/ /" duplicate. --}}
            <div class="flex flex-wrap items-center gap-1 px-4 py-2 text-sm">
                @foreach ($this->breadcrumbs() as $crumb)
                    @if ($loop->index > 1)
                        <span class="text-coollabs-subtle">/</span>
                    @endif
                    <button type="button" x-on:click="$wire.goTo(@js($crumb['path']))"
                        @class([
                            'rounded-md px-1.5 py-0.5 hover:bg-black/5 hover:text-current dark:hover:bg-white/[0.06]',
                            'text-current font-medium' => $loop->last,
                            'text-coollabs-subtle' => ! $loop->last,
                        ])>
                        {{ $crumb['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- Drag-and-drop file list --}}
            <div x-data="{ dragging: false }"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="dragging = false; if ($event.dataTransfer.files.length) { @this.upload('upload', $event.dataTransfer.files[0], () => $wire.uploadFile()) }"
                :class="dragging ? 'ring-2 ring-coollabs/40 dark:ring-warning/40' : ''"
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
                                    <x-reicon :name="$entry['type'] === 'dir' ? 'folder' : 'file'"
                                        class="size-4 {{ $entry['type'] === 'dir' ? 'text-coollabs dark:text-warning' : 'text-coollabs-subtle' }}" />
                                </div>
                                <div class="min-w-0">
                                    @if ($entry['type'] === 'dir')
                                        <button type="button" x-on:click="$wire.open(@js($entry['name']))"
                                            class="block w-full cursor-pointer truncate text-left font-medium hover:text-coollabs hover:underline dark:hover:text-warning">{{ $entry['name'] }}</button>
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
                                            @submit.prevent="$wire.chmodEntry(@js($entry['name']), mode); $dispatch('close-modal')"
                                            class="flex flex-col gap-4">
                                            <p class="text-sm text-coollabs-subtle">
                                                Set the octal permission mode for
                                                <span class="font-medium">{{ $entry['name'] }}</span>.
                                            </p>
                                            <x-forms.input id="chmodMode-{{ $loop->index }}" label="Octal mode"
                                                x-model="mode" placeholder="644" required />
                                            <div class="flex justify-end">
                                                <x-forms.button type="submit" isHighlighted>Apply</x-forms.button>
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
                                    x-data="{ open: false, name: @js($entry['name']) }"
                                    @keydown.escape.window="open = false">
                                    <div class="relative">
                                        <x-forms.button type="button" title="Actions"
                                            x-on:click="open = !open" @click.outside="open = false"
                                            x-bind:class="open && 'bg-coollabs/10 text-coollabs ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25'">
                                            <x-reicon name="dots-vertical" class="size-3.5" />
                                        </x-forms.button>
                                        <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                                            class="listbox-panel right-0! left-auto! z-30!">
                                            @if ($entry['type'] !== 'dir')
                                                <button type="button" class="listbox-option"
                                                    x-on:click="open = false; $wire.openEditor(name)">
                                                    <x-reicon name="file-content" class="size-3.5 shrink-0" />
                                                    <span class="min-w-0 flex-1 text-left">Edit</span>
                                                </button>
                                            @endif
                                            <button type="button" class="listbox-option"
                                                x-on:click="open = false; $wire.download(name)">
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
                                                    @submit.prevent="$wire.renameEntry(name, newName); $dispatch('close-modal')"
                                                    class="flex flex-col gap-4">
                                                    <x-forms.input id="renameName-{{ $loop->index }}"
                                                        label="New name" x-model="newName" required />
                                                    <div class="flex justify-end">
                                                        <x-forms.button type="submit" isHighlighted>Rename</x-forms.button>
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
                                                        <x-forms.button isError type="button"
                                                            x-on:click="$wire.deleteEntry(name); $dispatch('close-modal')">Delete</x-forms.button>
                                                    </div>
                                                </div>
                                            </x-modal-input>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Editor modal (opens when a text file is loaded) --}}
            <x-modal-input title="Edit file" :wireOpen="'editorOpen'" :content="true" wireIgnore="false" isLarge>
                <x-slot:content>
                    <span class="hidden"></span>
                </x-slot:content>
                <form wire:submit="saveEditor" class="flex flex-col gap-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate font-mono text-xs text-coollabs-subtle">{{ $editingPath }}</p>
                        @if ($editorLanguage)
                            <span class="shrink-0 rounded-md bg-coollabs/10 px-1.5 py-0.5 font-mono text-[11px] text-coollabs dark:bg-warning/15 dark:text-warning">{{ $editorLanguage }}</span>
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

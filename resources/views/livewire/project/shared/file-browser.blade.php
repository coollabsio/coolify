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
                        <button type="button" wire:click="selectContainer('{{ $name }}')"
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
                    <button type="button" wire:click="goTo('{{ $crumb['path'] }}')"
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
                                        <button type="button" wire:click="open('{{ $entry['name'] }}')"
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
                                <div class="text-right text-coollabs-subtle tabular-nums">
                                    {{ $entry['type'] === 'dir' ? '-' : formatBytes($entry['size']) }}
                                </div>
                                <div class="text-coollabs-subtle tabular-nums">
                                    {{ $entry['mtime'] ? \Illuminate\Support\Carbon::createFromTimestamp($entry['mtime'])->toDateTimeString() : '-' }}
                                </div>
                                <div class="flex items-center justify-end gap-0.5">
                                    @if ($entry['type'] !== 'dir')
                                        <x-forms.button type="button" title="Edit"
                                            wire:click="openEditor('{{ $entry['name'] }}')">
                                            <x-reicon name="file-content" class="size-3.5" />
                                        </x-forms.button>
                                    @endif
                                    <x-forms.button type="button" title="Download"
                                        wire:click="download('{{ $entry['name'] }}')">
                                        <x-reicon name="download" class="size-3.5" />
                                    </x-forms.button>

                                    {{-- Rename --}}
                                    <x-modal-input title="Rename">
                                        <x-slot:content>
                                            <x-forms.button type="button" title="Rename">
                                                <x-reicon name="edit" class="size-3.5" />
                                            </x-forms.button>
                                        </x-slot:content>
                                        <form x-data="{ name: @js($entry['name']) }"
                                            @submit.prevent="$wire.renameEntry(@js($entry['name']), name); $dispatch('close-modal')"
                                            class="flex flex-col gap-4">
                                            <x-forms.input id="renameName-{{ $loop->index }}"
                                                label="New name" x-model="name" required />
                                            <div class="flex justify-end">
                                                <x-forms.button type="submit" isHighlighted>Rename</x-forms.button>
                                            </div>
                                        </form>
                                    </x-modal-input>

                                    {{-- Delete --}}
                                    <x-modal-input title="Delete this item?">
                                        <x-slot:content>
                                            <x-forms.button isError type="button" title="Delete">
                                                <x-reicon name="trash" class="size-3.5" />
                                            </x-forms.button>
                                        </x-slot:content>
                                        <div class="flex flex-col gap-4">
                                            <p class="text-sm text-coollabs-subtle">
                                                This permanently deletes
                                                <span class="font-medium">{{ $entry['name'] }}</span>
                                                inside the container. This cannot be undone.
                                            </p>
                                            <div class="flex justify-end">
                                                <x-forms.button isError type="button"
                                                    wire:click="deleteEntry('{{ $entry['name'] }}')"
                                                    @click="$dispatch('close-modal')">Delete</x-forms.button>
                                            </div>
                                        </div>
                                    </x-modal-input>
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
                    {{-- TipTap code editor (single code block, lowlight highlighting).
                         wire:ignore protects the ProseMirror DOM from Livewire morphs. --}}
                    <div wire:ignore x-data="fileEditor" x-on:load-file-editor.window="load($event.detail)"
                        class="min-w-0">
                        <div class="file-ide">
                            <div class="file-ide-gutter" x-ref="gutter" aria-hidden="true"></div>
                            <div class="file-ide-code">
                                <div x-ref="editor"></div>
                            </div>
                        </div>
                        <div class="file-ide-status">
                            <span x-ref="status">Ln 1, Col 1</span>
                            <span x-ref="statusRight" class="ml-auto"></span>
                        </div>
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

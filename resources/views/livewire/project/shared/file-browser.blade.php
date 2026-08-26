<div>
    <x-application.settings-section title="Files"
        helper="Browse and manage files inside the running container." :flush="true">

        @if ($containerRunning)
            <x-slot:actions>
                {{-- Upload --}}
                <form wire:submit="uploadFile" x-data class="contents">
                    <label
                        class="button flex cursor-pointer items-center gap-1"
                        title="Upload a file to this folder">
                        <x-reicon name="upload" class="size-3.5" />
                        Upload
                        <input type="file" class="hidden" wire:model="upload"
                            @change="$nextTick(() => $wire.uploadFile())" />
                    </label>
                </form>

                {{-- New folder --}}
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
                    <x-reicon name="refresh" class="size-3.5" />
                    Refresh
                </x-forms.button>
            </x-slot:actions>
        @endif

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

            {{-- Breadcrumb --}}
            <div class="flex flex-wrap items-center gap-1 px-4 py-2 text-sm">
                @foreach ($this->breadcrumbs() as $crumb)
                    @if (! $loop->first)
                        <span class="text-coollabs-subtle">/</span>
                    @endif
                    <button type="button" wire:click="goTo('{{ $crumb['path'] }}')"
                        class="rounded-md px-1.5 py-0.5 text-coollabs-subtle hover:bg-black/5 hover:text-current dark:hover:bg-white/[0.06]">
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
                    <table class="data-table">
                        <thead>
                            <tr class="data-table-header">
                                <th class="w-8"></th>
                                <th>Name</th>
                                <th class="w-28 text-right">Size</th>
                                <th class="w-40">Modified</th>
                                <th class="w-32 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr wire:key="entry-{{ $entry['name'] }}" class="data-table-row">
                                    <td>
                                        <x-reicon
                                            :name="$entry['type'] === 'dir' ? 'folder' : 'file'"
                                            class="size-4 text-coollabs-subtle" />
                                    </td>
                                    <td>
                                        @if ($entry['type'] === 'dir')
                                            <button type="button" wire:click="open('{{ $entry['name'] }}')"
                                                class="text-left hover:underline">{{ $entry['name'] }}</button>
                                        @else
                                            <span>{{ $entry['name'] }}</span>
                                        @endif
                                    </td>
                                    <td class="text-right text-coollabs-subtle">
                                        {{ $entry['type'] === 'dir' ? '-' : \Illuminate\Support\Number::fileSize($entry['size']) }}
                                    </td>
                                    <td class="text-coollabs-subtle">
                                        {{ $entry['mtime'] ? \Illuminate\Support\Carbon::createFromTimestamp($entry['mtime'])->toDateTimeString() : '-' }}
                                    </td>
                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            @if ($entry['type'] !== 'dir')
                                                <x-forms.button type="button"
                                                    wire:click="openEditor('{{ $entry['name'] }}')">Edit</x-forms.button>
                                            @endif
                                            <x-forms.button type="button"
                                                wire:click="download('{{ $entry['name'] }}')">Download</x-forms.button>

                                            {{-- Rename --}}
                                            <x-modal-input buttonTitle="Rename" title="Rename">
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
                                            <x-modal-input buttonTitle="Delete" title="Delete this item?"
                                                isErrorButton>
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
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Editor modal (opens when a text file is loaded) --}}
            <x-modal-input title="Edit file" :wireOpen="'editorOpen'" :content="true" wireIgnore="false" isLarge>
                <x-slot:content>
                    <span class="hidden"></span>
                </x-slot:content>
                <form wire:submit="saveEditor" class="flex flex-col gap-4">
                    <p class="truncate text-xs text-coollabs-subtle">{{ $editingPath }}</p>
                    <x-forms.textarea id="editorContent" wire:model="editorContent" rows="20"
                        class="font-mono"></x-forms.textarea>
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

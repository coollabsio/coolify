<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Docker Images | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'all' }"
        class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="docker-images" />
        <div class="w-full">
            <div class="flex items-center gap-2">
                <h2>Docker Images</h2>
                <div class="flex items-center gap-2 ml-auto">
                    <span class="text-xs text-neutral-500">
                        {{ $this->images->count() }} images &middot;
                        {{ $this->danglingCount }} dangling &middot;
                        {{ $this->totalSize }} total
                    </span>
                </div>
            </div>
            <div class="mt-1 mb-6">Manage Docker images on your server.</div>

            @if ($this->danglingCount > 0)
                <x-callout type="warning" title="Unused Images Detected">
                    <p>{{ $this->danglingCount }} dangling images can be safely removed to free up disk space.</p>
                    <div class="flex gap-2 mt-2">
                        <x-modal-confirmation title="Prune Dangling Images?"
                            buttonTitle="Prune Dangling Images" isHighlightedButton
                            submitAction="pruneDangling"
                            :actions="['Permanently deletes all untagged (dangling) images']"
                            :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Prune Dangling Images" />
                        <x-modal-confirmation title="Prune All Unused Images?"
                            buttonTitle="Prune All" isDangerButton
                            submitAction="pruneAll"
                            :actions="[
                                'Permanently deletes all unused images',
                                'Clears build cache',
                            ]"
                            :confirmWithText="false" :confirmWithPassword="false"
                            step2ButtonText="Prune All" />
                    </div>
                </x-callout>
            @endif

            <div class="mt-6">
                @if ($this->images->isEmpty())
                    <div class="text-neutral-500 text-sm">No Docker images found on this server.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-neutral-500 border-b border-neutral-700">
                                    <th class="pb-2 pr-4">Repository</th>
                                    <th class="pb-2 pr-4">Tag</th>
                                    <th class="pb-2 pr-4">Image ID</th>
                                    <th class="pb-2 pr-4">Size</th>
                                    <th class="pb-2 pr-4">Created</th>
                                    <th class="pb-2 pr-4">Used By</th>
                                    <th class="pb-2 pr-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->images as $image)
                                    <tr wire:key="img-{{ md5($image['repo_tag'].$image['id']) }}"
                                        class="border-b border-neutral-800 hover:bg-neutral-900/50 {{ $image['is_dangling'] ? 'opacity-60' : '' }}">
                                        <td class="py-2 pr-4 font-mono text-xs max-w-[200px] truncate">
                                            {{ $image['repository'] }}
                                        </td>
                                        <td class="py-2 pr-4">
                                            <span
                                                class="px-1.5 py-0.5 text-xs rounded {{ $image['is_dangling'] ? 'bg-red-900/30 text-red-400' : 'bg-neutral-800 text-neutral-300' }}">
                                                {{ $image['tag'] }}
                                            </span>
                                        </td>
                                        <td class="py-2 pr-4 font-mono text-xs text-neutral-400">
                                            {{ strlen($image['id']) > 12 ? substr($image['id'], 7, 12) : $image['id'] }}
                                        </td>
                                        <td class="py-2 pr-4 text-xs">{{ $image['size'] }}</td>
                                        <td class="py-2 pr-4 text-xs text-neutral-400">{{ $image['created_at'] }}</td>
                                        <td class="py-2 pr-4 text-xs max-w-[150px] truncate">
                                            @if ($image['is_used'])
                                                <span class="text-emerald-400">{{ count($image['used_by']) }}
                                                    container(s)</span>
                                            @else
                                                <span class="text-neutral-500">Not used</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-2">
                                            @can('update', $server)
                                                @if (!$image['is_used'])
                                                    <x-modal-confirmation
                                                        title="Delete image {{ $image['repo_tag'] }}?"
                                                        buttonTitle="Delete"
                                                        isDangerButton
                                                        submitAction="deleteImage('{{ $image['repo_tag'] }}')"
                                                        :actions="['This action cannot be undone.']"
                                                        :confirmWithText="false"
                                                        :confirmWithPassword="false"
                                                        step2ButtonText="Delete Image" />
                                                @else
                                                    <span class="text-xs text-neutral-600">In use</span>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

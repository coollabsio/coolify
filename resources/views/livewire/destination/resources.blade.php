<div>
    <x-slot:title>
        {{ $destination->name }} Resources | Coolify
    </x-slot>

    @include('livewire.destination.navbar', ['destination' => $destination])

    <div x-data="{ search: '' }" class="application-settings-form">
        <x-application.settings-section title="Resources"
            description="Applications, databases, and services connected to this Docker network." flush>
            @if (count($resources) === 0)
                <x-empty title="No resources use this destination"
                    description="Resources will appear here after they are deployed to this network." size="sm">
                    <x-slot:icon>
                        <x-reicon name="destinations" class="size-6" />
                    </x-slot:icon>
                </x-empty>
            @else
                <div class="border-b border-neutral-200 p-3 dark:border-white/[0.08]">
                    <div class="relative w-full max-w-sm">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2.5 z-10 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input x-model.debounce.150ms="search" type="search" placeholder="Search resources"
                            class="h-8! w-full rounded-lg! border-neutral-200! bg-white! py-0! pr-3! pl-8! text-[12px]! shadow-none! placeholder:text-neutral-400 focus:border-neutral-300! focus:ring-0! dark:border-white/[0.08]! dark:bg-white/[0.035]! dark:text-fg! dark:placeholder:text-fg-faint">
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <div
                        class="grid min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem_2rem] border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                        <div>Project</div>
                        <div>Environment</div>
                        <div>Resource</div>
                        <div>Type</div>
                        <div></div>
                    </div>
                    @foreach ($resources as $row)
                        @if ($row['url'])
                            <a {{ wireNavigate() }} href="{{ $row['url'] }}"
                                wire:key="destination-resource-{{ $row['type'] }}-{{ $row['uuid'] }}"
                                x-show="search === '' || '{{ addslashes($row['search']) }}'.includes(search.toLowerCase())"
                                class="grid min-h-13 min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem_2rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 hover:no-underline dark:border-white/[0.07] dark:hover:bg-white/[0.025]">
                                <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $row['project'] }}</span>
                                <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $row['environment'] }}</span>
                                <span class="truncate font-medium text-black dark:text-fg">{{ $row['name'] }}</span>
                                <span class="text-neutral-500 dark:text-fg-dim">{{ ucfirst($row['type']) }}</span>
                                <x-reicon name="arrow-right"
                                    class="size-3.5 justify-self-end text-neutral-400 dark:text-fg-faint" />
                            </a>
                        @else
                            <div wire:key="destination-resource-{{ $row['type'] }}-{{ $row['uuid'] }}"
                                x-show="search === '' || '{{ addslashes($row['search']) }}'.includes(search.toLowerCase())"
                                class="grid min-h-13 min-w-[680px] grid-cols-[minmax(10rem,.8fr)_minmax(10rem,.8fr)_minmax(12rem,1fr)_8rem_2rem] items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] last:border-b-0 dark:border-white/[0.07]">
                                <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $row['project'] }}</span>
                                <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $row['environment'] }}</span>
                                <span class="truncate font-medium text-black dark:text-fg">{{ $row['name'] }}</span>
                                <span class="text-neutral-500 dark:text-fg-dim">{{ ucfirst($row['type']) }}</span>
                                <span></span>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    </div>
</div>

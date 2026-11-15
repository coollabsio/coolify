@if ($workload->isPreview())
    {{-- Each deploy of the pull request writes these from the parent (decision 0025), so they are read-only here. --}}
    @php $previewVariables = $workload->environment_variables()->orderBy('key')->get(['key', 'is_buildtime', 'is_runtime']); @endphp
    <x-application.settings-section id="environment-variables-section" title="Environment variables" :flush="$previewVariables->isNotEmpty()"
        helper="The preview variables of the parent application, and its production values when the parent allows them. Edit them on the Previews tab of the parent. They apply at the next deploy of the pull request.">
        @if ($previewVariables->isEmpty())
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">This preview has no variables.</p>
        @else
            <div class="data-table-header grid-cols-[minmax(0,1fr)_6rem_6rem]">
                <span>Name</span>
                <span class="text-center">Buildtime</span>
                <span class="text-center">Runtime</span>
            </div>
            <div class="data-table w-full">
                @foreach ($previewVariables as $variable)
                    <div wire:key="cluster-application-preview-variable-{{ $variable->key }}" class="data-table-row grid-cols-[minmax(0,1fr)_6rem_6rem] py-2!">
                        <span class="truncate font-mono text-[12px]">{{ $variable->key }}</span>
                        <span class="text-center text-[12px]">{{ $variable->is_buildtime ? 'Yes' : 'No' }}</span>
                        <span class="text-center text-[12px]">{{ $variable->is_runtime ? 'Yes' : 'No' }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
@else
    <livewire:project.shared.environment-variable.all :resource="$workload" wire:key="cluster-application-env-{{ $workload->uuid }}" />
@endif

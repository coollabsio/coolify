<div>
    <x-slot:title>
        {{ __('projects.title') }}
    </x-slot>
    <div class="flex gap-2">
        <h1>{{ __('projects.heading') }}</h1>
        @can('createAnyResource')
            <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_project') }}">
                <livewire:project.add-empty />
            </x-modal-input>
        @endcan
    </div>
    <div class="subtitle">{{ __('projects.subtitle') }}</div>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 -mt-1">
        @foreach ($projects as $project)
            <div class="relative gap-2 cursor-pointer coolbox group">
                <a href="{{ $project->navigateTo() }}" class="absolute inset-0"></a>
                <div class="flex flex-1 mx-6">
                    <div class="flex flex-col justify-center flex-1">
                        <div class="box-title">{{ $project->name }}</div>
                        <div class="box-description">
                            {{ $project->description }}
                        </div>
                    </div>
                    <div class="relative z-10 flex items-center justify-center gap-4 text-xs font-bold">
                        @if ($project->environments->first())
                            @can('createAnyResource')
                                <a class="hover:underline" {{ wireNavigate() }}
                                    href="{{ route('project.resource.create', [
                                        'project_uuid' => $project->uuid,
                                        'environment_uuid' => $project->environments->first()->uuid,
                                    ]) }}">
                                    {{ __('button.add_resource') }}
                                </a>
                            @endcan
                        @endif
                        @can('update', $project)
                            <a class="hover:underline" {{ wireNavigate() }}
                                href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}">
                                {{ __('button.settings') }}
                            </a>
                        @endcan
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>

<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-end gap-2">
            <h1>Environment: {{ data_get($environment, 'name') }}</h1>
            <x-forms.button type="submit">Save</x-forms.button>
            <livewire:project.delete-environment :disabled="!$environment->isEmpty()" :environment_id="$environment->id" />
        </div>
        <nav class="flex pt-2 pb-10">
            <ol class="flex items-center">
                <li class="inline-flex items-center">
                    <a class="text-xs truncate lg:text-sm"
                        href="{{ route('project.show', ['project_uuid' => data_get($parameters, 'project_uuid')]) }}">
                        {{ $project->name }}</a>
                </li>
                <li>
                    <div class="flex items-center">
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold dark:text-warning" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                        <a class="text-xs truncate lg:text-sm"
                            href="{{ route('project.resource.index', ['environment_name' => data_get($parameters, 'environment_name'), 'project_uuid' => data_get($parameters, 'project_uuid')]) }}">{{ data_get($parameters, 'environment_name') }}</a>
                    </div>
                </li>
                <li>
                    <div class="flex items-center">
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold dark:text-warning" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                        Edit
                    </div>
                </li>
            </ol>
        </nav>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="environment.name" />
            <x-forms.input label="Description" id="environment.description" />
        </div>
    </form>
    <div class="flex gap-2 pt-10">
        <h2>Shared Variables</h2>
        <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
            <livewire:project.shared.environment-variable.add />
        </x-modal-input>
    </div>
    <div class="flex items-center gap-2 pb-4">You can use these variables anywhere with <span class="dark:text-warning text-coollabs">@{{environment.VARIABLENAME}}</span><x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>
    <div class="flex flex-col gap-2">
        @forelse ($environment->environment_variables->sort()->sortBy('real_value') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="environment" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
    </div>
</div>

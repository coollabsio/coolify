<div>
    <x-slot:title>
        Create Service | {{ $project->name }}
    </x-slot>
    <x-slot:content>
        <div class="flex flex-col gap-8">
            <div>
                <h1>Create New Service</h1>
                <p>Choose a template or create a custom service for {{ $project->name }}</p>
            </div>

            @if (!$selectedTemplate)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    @forelse ($templates as $template)
                        <div class="card cursor-pointer hover:bg-coollabs-100 dark:hover:bg-coollabs-800 transition-colors"
                             wire:click="selectTemplate('{{ $template['template_path'] }}')">
                            <div class="card-body">
                                <div class="flex items-start gap-4">
                                    @if (isset($template['icon']))
                                        <div class="flex-shrink-0">
                                            <x-icon name="{{ $template['icon'] }}" class="w-8 h-8 text-coollabs" />
                                        </div>
                                    @endif
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-semibold text-lg mb-2">{{ $template['name'] }}</h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                                            {{ $template['description'] }}
                                        </p>
                                        @if (isset($template['tags']))
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($template['tags'] as $tag)
                                                    <span class="badge badge-sm">{{ $tag }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if (isset($template['version']))
                                            <div class="mt-2 text-xs text-gray-500">
                                                v{{ $template['version'] }} by {{ $template['author'] ?? 'Community' }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-span-full text-center py-8">
                            <p class="text-gray-500">No service templates available</p>
                        </div>
                    @endforelse
                </div>
                
                <div class="mt-6">
                    <button type="button" class="btn btn-outline" 
                            wire:click="$set('useTemplate', false)">
                        Create Custom Service
                    </button>
                </div>
            @else
                <form wire:submit="createService">
                    <div class="card">
                        <div class="card-header flex items-center justify-between">
                            <div>
                                <h2 class="text-xl font-semibold">{{ $selectedTemplate['name'] }}</h2>
                                <p class="text-gray-600 dark:text-gray-400">{{ $selectedTemplate['description'] }}</p>
                            </div>
                            <button type="button" class="btn btn-ghost btn-sm"
                                    wire:click="$set('selectedTemplate', null)">
                                <x-icon name="x" class="w-4 h-4" />
                            </button>
                        </div>
                        
                        <div class="card-body space-y-6">
                            <div>
                                <x-forms.input
                                    label="Service Name"
                                    wire:model="serviceName"
                                    placeholder="Enter service name"
                                    required
                                />
                            </div>

                            @if (isset($selectedTemplate['environment_variables']) && count($selectedTemplate['environment_variables']) > 0)
                                <div>
                                    <h3 class="text-lg font-medium mb-4">Configuration</h3>
                                    <div class="space-y-4">
                                        @foreach ($selectedTemplate['environment_variables'] as $envVar)
                                            <div>
                                                <x-forms.input
                                                    label="{{ $envVar['name'] }}"
                                                    wire:model="environmentVariables.{{ $envVar['name'] }}"
                                                    placeholder="{{ $envVar['example'] ?? '' }}"
                                                    helper="{{ $envVar['description'] ?? '' }}"
                                                    :required="$envVar['required'] ?? false"
                                                />
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (isset($selectedTemplate['ports']) && count($selectedTemplate['ports']) > 0)
                                <div>
                                    <h3 class="text-lg font-medium mb-2">Ports</h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        @foreach ($selectedTemplate['ports'] as $port)
                                            <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                                <div class="flex justify-between items-center">
                                                    <span class="font-mono text-sm">{{ $port['port'] }}</span>
                                                    <span class="text-xs text-gray-600 dark:text-gray-400">
                                                        {{ $port['description'] ?? '' }}
                                                    </span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (isset($selectedTemplate['volumes']) && count($selectedTemplate['volumes']) > 0)
                                <div>
                                    <h3 class="text-lg font-medium mb-2">Persistent Volumes</h3>
                                    <div class="space-y-2">
                                        @foreach ($selectedTemplate['volumes'] as $volume)
                                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                                                <div>
                                                    <span class="font-medium">{{ $volume['name'] }}</span>
                                                    @if (isset($volume['description']))
                                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                                            {{ $volume['description'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                                @if ($volume['required'] ?? false)
                                                    <span class="badge badge-warning badge-sm">Required</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            @if (isset($selectedTemplate['documentation']))
                                <div>
                                    <h3 class="text-lg font-medium mb-2">Documentation</h3>
                                    <div class="prose prose-sm dark:prose-invert max-w-none">
                                        {!! Str::markdown($selectedTemplate['documentation']) !!}
                                    </div>
                                </div>
                            @endif
                        </div>
                        
                        <div class="card-footer flex justify-end gap-4">
                            <button type="button" class="btn btn-outline"
                                    wire:click="$set('selectedTemplate', null)">
                                Back to Templates
                            </button>
                            <button type="submit" class="btn btn-primary">
                                Create Service
                            </button>
                        </div>
                    </div>
                </form>
            @endif

            @if (!$useTemplate && !$selectedTemplate)
                <form wire:submit="createService">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="text-xl font-semibold">Custom Service</h2>
                            <p class="text-gray-600 dark:text-gray-400">Create a service with your own Docker Compose configuration</p>
                        </div>
                        
                        <div class="card-body space-y-6">
                            <div>
                                <x-forms.input
                                    label="Service Name"
                                    wire:model="serviceName"
                                    placeholder="Enter service name"
                                    required
                                />
                            </div>

                            <div>
                                <x-forms.textarea
                                    label="Docker Compose"
                                    wire:model="customCompose"
                                    placeholder="Paste your docker-compose.yml content here"
                                    rows="20"
                                    required
                                />
                            </div>
                        </div>
                        
                        <div class="card-footer flex justify-end gap-4">
                            <button type="button" class="btn btn-outline"
                                    wire:click="$set('useTemplate', true)">
                                Back to Templates
                            </button>
                            <button type="submit" class="btn btn-primary">
                                Create Service
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    </x-slot>
</div>
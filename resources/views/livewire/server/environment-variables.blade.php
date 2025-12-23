<div>
    <div class="flex items-center gap-2 mb-4">
        <h3 class="text-lg font-medium">Environment Variables</h3>
        <div class="flex-1"></div>
        <button wire:click="$toggle('show_new_form')" class="btn btn-sm btn-primary">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Add Environment Variable
        </button>
    </div>

    <div wire:loading.delay.longer class="flex items-center justify-center py-8">
        <svg class="animate-spin h-5 w-5 text-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span class="ml-2">Loading...</span>
    </div>

    <!-- New Environment Variable Form -->
    @if ($show_new_form)
        <div class="bg-base-200 dark:bg-base-800 rounded-lg p-4 mb-4">
            <h4 class="text-md font-medium mb-3">Add New Environment Variable</h4>
            <form wire:submit="createEnvironmentVariable">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Key *</label>
                        <input type="text" wire:model.live="new_environment_variable.key" class="input input-sm w-full" placeholder="ENV_VAR_KEY" required>
                        @error('new_environment_variable.key') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Value</label>
                        <textarea wire:model.live="new_environment_variable.value" class="textarea textarea-sm w-full" rows="1" placeholder="environment-variable-value"></textarea>
                        @error('new_environment_variable.value') <span class="text-error text-xs">{{ $message }}</span> @enderror
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-4 mt-3">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="new_environment_variable.is_literal" class="checkbox checkbox-sm">
                        <span class="text-sm">Literal</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="new_environment_variable.is_multiline" class="checkbox checkbox-sm">
                        <span class="text-sm">Multiline</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="new_environment_variable.is_buildtime" class="checkbox checkbox-sm">
                        <span class="text-sm">Build Time</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="new_environment_variable.is_runtime" class="checkbox checkbox-sm">
                        <span class="text-sm">Runtime</span>
                    </label>
                </div>
                
                <div class="flex gap-2 mt-4">
                    <button type="submit" class="btn btn-sm btn-primary">Create</button>
                    <button type="button" wire:click="$toggle('show_new_form')" class="btn btn-sm btn-ghost">Cancel</button>
                </div>
            </form>
        </div>
    @endif

    <!-- Environment Variables List -->
    @if (count($environment_variables) > 0)
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>Key</th>
                        <th>Value</th>
                        <th>Literal</th>
                        <th>Multiline</th>
                        <th>Build Time</th>
                        <th>Runtime</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($environment_variables as $index => $env)
                        <tr>
                            @if ($editing_environment_variable && $editing_environment_variable['index'] == $index)
                                <!-- Edit Mode -->
                                <td colspan="7">
                                    <form wire:submit="updateEnvironmentVariable">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium mb-1">Key</label>
                                                <input type="text" wire:model.live="editing_environment_variable.key" class="input input-sm w-full" required>
                                                @error('editing_environment_variable.key') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium mb-1">Value</label>
                                                <textarea wire:model.live="editing_environment_variable.value" class="textarea textarea-sm w-full" rows="1"></textarea>
                                                @error('editing_environment_variable.value') <span class="text-error text-xs">{{ $message }}</span> @enderror
                                            </div>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-4 mt-3">
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model.live="editing_environment_variable.is_literal" class="checkbox checkbox-sm">
                                                <span class="text-sm">Literal</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model.live="editing_environment_variable.is_multiline" class="checkbox checkbox-sm">
                                                <span class="text-sm">Multiline</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model.live="editing_environment_variable.is_buildtime" class="checkbox checkbox-sm">
                                                <span class="text-sm">Build Time</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input type="checkbox" wire:model.live="editing_environment_variable.is_runtime" class="checkbox checkbox-sm">
                                                <span class="text-sm">Runtime</span>
                                            </label>
                                        </div>
                                        
                                        <div class="flex gap-2 mt-4">
                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                            <button type="button" wire:click="cancelEdit" class="btn btn-sm btn-ghost">Cancel</button>
                                        </div>
                                    </form>
                                </td>
                            @else
                                <!-- Display Mode -->
                                <td class="font-mono text-sm">{{ $env['key'] }}</td>
                                <td class="font-mono text-sm">
                                    @if ($env['is_literal'] || $env['is_multiline'])
                                        {{ Str::limit($env['value'], 50) }}
                                    @else
                                        {{ Str::limit($env['value'], 50) }}
                                    @endif
                                </td>
                                <td>
                                    @if ($env['is_literal'])
                                        <span class="badge badge-primary">Yes</span>
                                    @else
                                        <span class="badge badge-ghost">No</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($env['is_multiline'])
                                        <span class="badge badge-primary">Yes</span>
                                    @else
                                        <span class="badge badge-ghost">No</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($env['is_buildtime'])
                                        <span class="badge badge-primary">Yes</span>
                                    @else
                                        <span class="badge badge-ghost">No</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($env['is_runtime'])
                                        <span class="badge badge-primary">Yes</span>
                                    @else
                                        <span class="badge badge-ghost">No</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex gap-1">
                                        <button wire:click="startEdit({{ $index }})" class="btn btn-xs btn-ghost">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                            </svg>
                                        </button>
                                        <button wire:click="deleteEnvironmentVariable({{ $env['id'] }})" class="btn btn-xs btn-ghost text-error">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="text-center py-8 text-base-content/60">
            <svg class="w-12 h-12 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
            </svg>
            <p class="text-lg font-medium mb-2">No Environment Variables</p>
            <p class="text-sm">Add environment variables to customize this server's deployments.</p>
        </div>
    @endif

    <!-- Server Identity Variables Info -->
    <div class="bg-info/10 border border-info/20 rounded-lg p-4 mt-6">
        <h4 class="text-md font-medium mb-2 flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Automatic Server Identity Variables
        </h4>
        <p class="text-sm mb-3">The following variables are automatically injected into all deployments on this server:</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
            <div class="font-mono bg-base-300 rounded px-2 py-1">
                <strong>COOLIFY_SERVER_ID:</strong> {{ $server->id }}
            </div>
            <div class="font-mono bg-base-300 rounded px-2 py-1">
                <strong>COOLIFY_SERVER_NAME:</strong> {{ $server->name }}
            </div>
            <div class="font-mono bg-base-300 rounded px-2 py-1">
                <strong>COOLIFY_SERVER_HOSTNAME:</strong> {{ $server->ip }}
            </div>
            <div class="font-mono bg-base-300 rounded px-2 py-1">
                <strong>COOLIFY_SERVER_IP:</strong> {{ $server->ip }}
            </div>
        </div>
        <p class="text-xs mt-3 text-base-content/60">These variables are read-only and cannot be overridden by user-defined environment variables.</p>
    </div>
</div>

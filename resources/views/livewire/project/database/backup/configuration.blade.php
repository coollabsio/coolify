<div>
    <form wire:submit.prevent="submit" class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <h2 class="text-2xl font-bold">Backup Configuration</h2>
        </div>

        {{-- pgBackRest Section (PostgreSQL only) --}}
        @if($database instanceof \App\Models\StandalonePostgresql)
            <div class="p-4 border rounded-lg bg-white dark:bg-coolgray-100">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            pgBackRest - Enterprise Backup Solution
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                            Advanced backup engine with incremental backups, parallel processing, and significant S3 cost savings.
                        </p>
                        
                        <div class="mt-3 space-y-2 text-sm">
                            <div class="flex items-center gap-2">
                                @if($pgbackrest_configured)
                                    <span class="px-2 py-1 bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded-md">
                                        ✓ Configured
                                    </span>
                                    <span class="text-gray-600 dark:text-gray-400">Stanza: {{ $stanza_name }}</span>
                                @else
                                    <span class="px-2 py-1 bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200 rounded-md">
                                        Not Configured
                                    </span>
                                @endif
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2 mt-3">
                                <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Incremental backups
                                </div>
                                <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    90% smaller backups
                                </div>
                                <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Point-in-time recovery
                                </div>
                                <div class="flex items-center gap-2 text-green-600 dark:text-green-400">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                    Huge S3 cost savings
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ml-4">
                        @if($backup_engine === 'pgbackrest')
                            <button type="button" wire:click="disablePgBackRest" 
                                    class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md transition-colors">
                                Switch to pg_dump
                            </button>
                        @else
                            <button type="button" wire:click="enablePgBackRest" 
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md transition-colors">
                                Enable pgBackRest
                            </button>
                        @endif
                    </div>
                </div>
                
                @if($backup_engine === 'pgbackrest')
                    <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-md">
                        <p class="text-sm text-blue-800 dark:text-blue-200">
                            <strong>Active:</strong> Your backups are using pgBackRest. 
                            Incremental backups will run automatically based on your schedule. 
                            A full backup is performed weekly, with differential backups daily and incrementals in between.
                        </p>
                    </div>
                @endif
            </div>
        @endif

        {{-- Backup Engine Display --}}
        <div class="flex items-center gap-2 text-sm">
            <span class="font-semibold">Current Backup Engine:</span>
            <span class="px-2 py-1 rounded-md {{ $backup_engine === 'pgbackrest' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-200' }}">
                {{ $backup_engine === 'pgbackrest' ? 'pgBackRest' : 'pg_dump (Legacy)' }}
            </span>
        </div>

        {{-- Enable/Disable Backups --}}
        <div class="flex items-center gap-2">
            <input type="checkbox" wire:model="enabled" id="enabled" class="rounded">
            <label for="enabled" class="font-medium">Enable Scheduled Backups</label>
        </div>

        {{-- Frequency --}}
        <div>
            <label for="frequency" class="block font-medium mb-2">Backup Frequency</label>
            <select wire:model="frequency" id="frequency" class="w-full px-3 py-2 border rounded-md dark:bg-coolgray-100">
                <option value="0 * * * *">Every Hour</option>
                <option value="0 0 * * *">Daily at Midnight</option>
                <option value="0 2 * * *">Daily at 2 AM</option>
                <option value="0 0 * * 0">Weekly (Sunday)</option>
                <option value="0 0 1 * *">Monthly</option>
            </select>
            @if($backup_engine === 'pgbackrest')
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    With pgBackRest, hourly backups are efficient due to incremental backup technology.
                </p>
            @endif
        </div>

        {{-- S3 Storage --}}
        <div>
            <div class="flex items-center gap-2 mb-2">
                <input type="checkbox" wire:model="save_s3" id="save_s3" class="rounded">
                <label for="save_s3" class="font-medium">Save to S3 Storage</label>
            </div>
            
            @if($save_s3)
                <select wire:model="s3_storage_id" class="w-full px-3 py-2 border rounded-md dark:bg-coolgray-100">
                    <option value="">Select S3 Storage</option>
                    @foreach(\App\Models\S3Storage::all() as $storage)
                        <option value="{{ $storage->id }}">{{ $storage->name }}</option>
                    @endforeach
                </select>
                
                @if($backup_engine === 'pgbackrest')
                    <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                        ✓ pgBackRest will dramatically reduce S3 storage costs through incremental backups
                    </p>
                @endif
            @endif
        </div>

        {{-- Retention Policies --}}
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="number_of_backups_locally" class="block font-medium mb-2">
                    Number of Backups to Keep
                </label>
                <input type="number" wire:model="number_of_backups_locally" id="number_of_backups_locally" 
                       min="0" class="w-full px-3 py-2 border rounded-md dark:bg-coolgray-100">
                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">0 = unlimited</p>
            </div>
            
            <div>
                <label for="backup_retention_days" class="block font-medium mb-2">
                    Days to Keep Backups
                </label>
                <input type="number" wire:model="backup_retention_days" id="backup_retention_days" 
                       min="0" class="w-full px-3 py-2 border rounded-md dark:bg-coolgray-100">
                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">0 = unlimited</p>
            </div>
        </div>

        @if($backup_engine === 'pgbackrest')
            <div class="p-3 bg-gray-50 dark:bg-coolgray-200 rounded-md text-sm">
                <h4 class="font-semibold mb-2">pgBackRest Backup Strategy:</h4>
                <ul class="list-disc list-inside space-y-1 text-gray-700 dark:text-gray-300">
                    <li><strong>Full Backup:</strong> Every 7 days (complete database copy)</li>
                    <li><strong>Differential Backup:</strong> Daily (changes since last full backup)</li>
                    <li><strong>Incremental Backup:</strong> All other times (changes since last backup)</li>
                </ul>
            </div>
        @endif

        {{-- Actions --}}
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md transition-colors">
                Save Configuration
            </button>
            
            <button type="button" wire:click="backupNow" 
                    class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md transition-colors">
                Backup Now
            </button>
        </div>
    </form>
</div>
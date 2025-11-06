<div>
    <div class="flex flex-col gap-2">
        <h3 class="font-bold">Automatic Image Updates</h3>
        <p class="text-sm">Automatically pull the latest images and restart the service on a schedule.</p>
        
        <div class="flex items-center gap-4 pt-2">
            <div class="flex items-center gap-2">
                <input 
                    type="checkbox" 
                    wire:model.live="auto_image_pull_enabled"
                    wire:change="toggleAutoPull"
                    id="auto_image_pull_enabled" 
                    class="checkbox checkbox-sm"
                />
                <label for="auto_image_pull_enabled" class="cursor-pointer">
                    Enable Automatic Updates
                </label>
            </div>
        </div>

        @if($auto_image_pull_enabled)
            <div class="pt-2">
                <label for="auto_image_pull_schedule" class="block text-sm font-medium pb-1">
                    Update Schedule
                </label>
                <select 
                    wire:model.live="auto_image_pull_schedule"
                    wire:change="updateAutoPullSchedule"
                    id="auto_image_pull_schedule" 
                    class="select select-sm select-bordered w-full max-w-xs"
                >
                    <option value="hourly">Hourly</option>
                    <option value="daily">Daily (2 AM)</option>
                    <option value="weekly">Weekly (Sunday 2 AM)</option>
                </select>
            </div>

            @if($service->last_image_pull_check)
                <div class="text-sm opacity-70 pt-1">
                    Last checked: {{ $service->last_image_pull_check->diffForHumans() }}
                </div>
            @endif
        @endif

        <div class="pt-2">
            <button wire:click="checkForUpdates" class="btn btn-sm btn-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12a9 9 0 1 1-6.219-8.56"/>
                    <path d="M13 4h8v8"/>
                </svg>
                Check for Updates Now
            </button>
        </div>

        <div class="text-xs opacity-60 pt-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 inline" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M12 16v-4"/>
                <path d="M12 8h.01"/>
            </svg>
            The service will automatically restart when new images are detected.
        </div>
    </div>
</div>

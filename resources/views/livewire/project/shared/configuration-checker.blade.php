<div>
    @if ($isConfigurationChanged && !is_null($resource->config_hash) && !$resource->isExited())
        <x-popup-small>
            <x-slot:title>
                The latest configuration has not been applied
            </x-slot:title>
            <x-slot:icon>
                <svg class="hidden w-10 h-10 dark:text-warning lg:block" viewBox="0 0 256 256"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor"
                        d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16" />
                </svg>
            </x-slot:icon>
            <x-slot:description>
                <span>Please restart (or redeploy) to apply the new configuration.</span>
            </x-slot:description>
            <x-slot:button-text @click="disableSponsorship()">
                Disable This Popup
            </x-slot:button-text>
        </x-popup-small>
    @endif
</div>

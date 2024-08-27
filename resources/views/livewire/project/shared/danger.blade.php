<div>
    <h2>Danger Zone</h2>
    <div class="">Woah. I hope you know what are you doing.</div>
    <h4 class="pt-4">Delete Resource</h4>
    <div class="pb-4">This will stop your containers, delete all related data, etc. Beware! There is no coming
        back!
    </div>

    <x-modal-confirmation isErrorButton buttonTitle="Delete" confirm={{ $confirm }}>
        <div x-data="{ step: 1, deleteText: '', selectedActions: [], getActionText(action) { 
            const actionTexts = {
                'delete_volumes': 'Delete associated volumes',
                'delete_connected_networks': 'Delete connected networks',
                'delete_configurations': 'Delete configuration files',
                'delete_images': 'Delete associated unused images'
            };
            return actionTexts[action] || action;
        } }">
            <!-- Step 1: Select actions -->
            <div x-show="step === 1">
                <div class="px-2 mb-4">Select the actions you want to perform:</div>
                <x-forms.checkbox id="delete_volumes" wire:model="delete_volumes" label="Permanently delete associated volumes?"></x-forms.checkbox>
                <x-forms.checkbox id="delete_connected_networks" wire:model="delete_connected_networks" label="Permanently delete connected networks, predefined networks are not deleted?"></x-forms.checkbox>
                <x-forms.checkbox id="delete_configurations" wire:model="delete_configurations" label="Permanently delete configuration files from the server?"></x-forms.checkbox>
                <x-forms.checkbox id="delete_images" wire:model="delete_images" label="Permanently delete associated unused images?"></x-forms.checkbox>
                <div class="flex justify-between mt-4">
                    <x-forms.button @click="$dispatch('close-modal')">Cancel</x-forms.button>
                    <x-forms.button 
                        x-show="step === 1"
                        @click="step = 2; selectedActions = [$wire.delete_volumes ? 'delete_volumes' : null, $wire.delete_connected_networks ? 'delete_connected_networks' : null, $wire.delete_configurations ? 'delete_configurations' : null, $wire.delete_images ? 'delete_images' : null].filter(Boolean)" 
                        x-bind:disabled="!($wire.delete_volumes || $wire.delete_connected_networks || $wire.delete_configurations || $wire.delete_images)"
                        class="w-24 bg-red-600 text-white hover:bg-red-700" 
                        type="button"
                    >
                        Continue
                    </x-forms.button>
                </div>
            </div>

            <!-- Step 2: Confirm deletion -->
            <div x-show="step === 2">
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p class="font-bold">Warning</p>
                    <p>This operation is not reversible. Please proceed with caution.</p>
                </div>
                <div class="px-2 mb-4">The following actions will be performed:</div>
                <ul class="mb-4 space-y-2">
                    <template x-for="action in selectedActions" :key="action">
                        <li class="flex items-center text-red-500">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span x-text="getActionText(action)" class="font-bold"></span>
                        </li>
                    </template>
                </ul>
                <div class="text-black dark:text-white mb-4">Please type <span class="text-red-500 font-bold">DELETE</span> to confirm this destructive action:</div>
                <input type="text" x-model="deleteText" class="w-full p-2 border border-violet-500 rounded mb-4 bg-violet-100 text-black focus:outline-none focus:ring-2 focus:ring-coolify-500">
                <div class="flex justify-between">
                    <x-forms.button @click="step = 1">Back</x-forms.button>
                    <x-forms.button 
                        isError 
                        type="button" 
                        @click="$wire.delete(selectedActions)"
                        x-bind:disabled="deleteText !== 'DELETE'"
                    >
                        Permanently Delete
                    </x-forms.button>
                </div>
            </div>
        </div>
    </x-modal-confirmation>
</div>

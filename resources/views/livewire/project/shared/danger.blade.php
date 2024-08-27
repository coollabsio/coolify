<div class="border border-red-600 rounded-md p-6 mt-8">
    <h2 class="text-3xl font-bold mb-4">Danger Zone</h2>

    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h4 class="text-lg font-semibold">Delete Resource</h4>
                <p class="text-gray-600 dark:text-gray-400">Once you delete a resource, there is no going back. Please be certain.</p>
            </div>
            <x-modal-confirmation 
                isError
                type="button"
                buttonTitle="Delete this resource" 
                title="Resource Deletion"
            >
                <div x-data="{ step: 1, deleteText: '', password: '', selectedActions: [], getActionText(action) { 
                    const actionTexts = {
                        'delete_volumes': 'All associated volumes of this resource will be deleted.',
                        'delete_connected_networks': 'All connected networks of this resource will be deleted (predefined networks are not deleted).',
                        'delete_configurations': 'All configuration files of this resource will be deleted on the server.',
                        'docker_cleanup': 'Docker cleanup will be executed which removes builder cache and unused images.'
                    };
                    return actionTexts[action] || action;
                } }">
                    <!-- Step 1: Select actions -->
                    <div x-show="step === 1">
                        <div class="flex justify-between items-center mb-4">
                            <div class="px-2">Select the actions you want to perform:</div>
                        </div>
                        <x-forms.checkbox id="delete_volumes" wire:model="delete_volumes" label="Permanently delete associated volumes?"></x-forms.checkbox>
                        <x-forms.checkbox id="delete_connected_networks" wire:model="delete_connected_networks" label="Permanently delete connected networks, predefined networks are not deleted?"></x-forms.checkbox>
                        <x-forms.checkbox id="delete_configurations" wire:model="delete_configurations" label="Permanently delete configuration files from the server?"></x-forms.checkbox>
                        <x-forms.checkbox id="docker_cleanup" wire:model="docker_cleanup" label="Run Docker cleanup (remove builder cache and unused images)?"></x-forms.checkbox>
                        <div class="flex justify-between mt-4">
                            <x-forms.button @click="$dispatch('close-modal')">Cancel</x-forms.button>
                            <x-forms.button isError x-show="step === 1" @click="step = 2; selectedActions = [$wire.delete_volumes ? 'delete_volumes' : null, $wire.delete_connected_networks ? 'delete_connected_networks' : null, $wire.delete_configurations ? 'delete_configurations' : null, $wire.docker_cleanup ? 'docker_cleanup' : null].filter(Boolean)" type="button">
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
                            <li class="flex items-center text-red-500">
                                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                <span class="font-bold">All containers of this resource will be stopped and permanently deleted.</span>
                            </li>
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
                        <input type="text" x-model="deleteText" class="w-full p-2 rounded mb-6 text-black input">
                        <div class="flex justify-between">
                            <x-forms.button @click="step = 1">Back</x-forms.button>
                            <x-forms.button isError type="button" @click="step = 3" x-bind:disabled="deleteText !== 'DELETE'">
                                Permanently Delete
                            </x-forms.button>
                        </div>
                    </div>

                    <!-- Step 3: Password confirmation -->
                    <div x-show="step === 3">
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                            <p class="font-bold">Final Confirmation</p>
                            <p>Please enter your password to confirm this destructive action.</p>
                        </div>
                        <div class="mb-4">
                            <label for="password-confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Your Password
                            </label>
                            <input type="password" id="password-confirm" x-model="password"
                                   class="input"
                                   placeholder="Enter your password">
                        </div>
                        <div class="flex justify-between">
                            <x-forms.button @click="step = 2">Back</x-forms.button>
                            <x-forms.button isError type="button" @click="$wire.delete(selectedActions, password)" x-bind:disabled="!password">
                                Confirm Deletion
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </x-modal-confirmation>
        </div>
    </div>
</div>

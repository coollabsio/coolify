<div>
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
            <div class="bg-white dark:bg-coolgray-100 rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-[90vh] overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl font-bold text-red-600 dark:text-red-400">
                            <svg class="w-6 h-6 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.962-.833-2.732 0L4.082 15.5c-.77.833.192 2.5 1.732 2.5z"></path>
                            </svg>
                            Generated Passwords - IMPORTANT!
                        </h2>
                        <button wire:click="closeModal" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-6">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-red-800 dark:text-red-200">
                                    Security Warning
                                </h3>
                                <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                    <p>These passwords have been generated for your services and <strong>will not be shown again</strong>. Please copy and store them securely in your password manager immediately.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        @foreach($passwords as $passwordData)
                            <div class="bg-gray-50 dark:bg-coolgray-200 rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $passwordData['variable_key'] }}
                                    </h4>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">
                                        Generated: {{ \Carbon\Carbon::parse($passwordData['timestamp'])->format('H:i:s') }}
                                    </span>
                                </div>
                                <x-forms.copy-button :text="$passwordData['password']" />
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                                    The hashed version of this password has been stored in your environment variables.
                                </p>
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex justify-end">
                        <x-forms.button wire:click="closeModal">
                            I have saved these passwords
                        </x-forms.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div> 